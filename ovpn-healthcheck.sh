#!/bin/bash
# ══════════════════════════════════════════════════════════════════
#  OVPNPlus — VPN Health Check daemon
# ══════════════════════════════════════════════════════════════════
#
# systemd Type=simple, Restart=always. Демон, а не таймер: только процесс,
# живущий постоянно, может хранить состояние между проверками — а без него
# нет ни cooldown, ни backoff, ни детекта flapping.
#
# Архитектура OVPNPlus:
#   tun0  — сервер для клиентов (подсеть читается ДИНАМИЧЕСКИ из server.conf)
#   tun1  — исходящий туннель ко второму VPN (загружается через панель)
#   Цепочка: ip rule from <подсеть> table 120 -> table 120: default dev tun1
#
#   Второй VPN запускается с --route-nopull, поэтому НЕ МОЖЕТ тронуть
#   основную таблицу маршрутизации. Это OpenVPN-аналог 'Table = off'
#   у WireGuard: маршруты туннеля живут только в таблице 120.
#
# Синхронизация с панелью: файл состояния (STATE=running|stopped|busy).
# Пока панель выполняет операцию (busy) — демон не вмешивается. Это
# устраняет гонку, из-за которой между 'stop' и 'rm' туннель поднимался
# обратно и оставался осиротевший интерфейс.
# ══════════════════════════════════════════════════════════════════

INTERFACE="tun1"
SERVER_IFACE="tun0"
UP_CONFIG="/etc/openvpn/upstream/tun1.conf"
SRV_CONF="/etc/openvpn/server/server.conf"
UPSTREAM_SVC="ovpnplus-upstream.service"
TABLE_ID="120"
STATE_FILE="/var/www/ovpnplus/state"
HEALTH_FILE="/var/www/ovpnplus/health"
ROUTES_FILE="/var/www/ovpnplus/routes.txt"
SETTINGS_FILE="/var/www/ovpnplus/settings"
NIC_FILE="/var/www/html/NIC.txt"
PANEL_PORT=8998          # адрес из lk.txt должен работать ВСЕГДА
# Порт SSH читаем из конфигурации, а не берём 22 вслепую: на сервере
# с перенесённым портом демон вечно открывал бы неиспользуемый 22,
# а настоящий порт оставался бы без гарантии доступа.
SSH_PORT=$(awk '/^[[:space:]]*Port[[:space:]]+[0-9]+/ {print $2; exit}' /etc/ssh/sshd_config 2>/dev/null)
[[ "$SSH_PORT" =~ ^[0-9]+$ ]] || SSH_PORT=22
LOG_DIR="/var/log/ovpnplus"
# ОДИН журнал на всю систему — туда же пишет панель.
# Формат одинаковый, различается только метка источника.
LOG="${LOG_DIR}/ovpnplus.log"
LOCK="/run/ovpn-healthcheck.lock"
MAX_LOG=2097152          # 2 MB
KEEP_LINES=1500

# Адреса для проверки связи. Пять штук из трёх разных сетей — если одна
# заблокирована или лежит, остальные всё равно ответят.
# Список обязан совпадать с OVP_PROBE_HOSTS в includes/ovp_helpers.php:
# панель запрещает добавлять их в обход, иначе проверка сломается.
PING_HOSTS=("8.8.8.8" "8.8.4.4" "1.1.1.1" "1.0.0.1" "9.9.9.9")
PING_TIMEOUT=1           # таймаут одного ping (с). Секунды достаточно:
                         # проверка идёт до публичных DNS, и если ответ
                         # не пришёл за секунду, вторая ничего не изменит.
CHECK_INTERVAL=2         # период цикла
RETRY_DELAY=0.4          # пауза перед второй попыткой ping (с)
POLL_MAX=9               # макс итераций ожидания подъёма после перезапуска.
                         # Итерация — это sleep 1 плюс полная проверка связи
                         # каждую третью, то есть реально около 25 секунд.
                         # Этого хватает на TLS-рукопожатие OpenVPN, которое
                         # заметно дольше обмена ключами у WireGuard. Больше
                         # ставить нельзя: всё это время цикл не читает
                         # состояние и не соблюдает флаг busy от панели.
WARMUP_TIMEOUT=120       # ждём стабилизации после старта (с)
COOLDOWN_INITIAL=10      # начальная пауза между попытками (с)
COOLDOWN_MAX=60          # потолок паузы (с)
BUSY_STALE=180           # если панель забыла снять busy (с)
RELOAD_INTERVAL=300      # перечитывание WAN и подсети tun0
DOWN_DEDUP=300           # одну и ту же причину не повторяем чаще (с)

# Значения по умолчанию — перезаписываются load_server_net()
VPN_GW="10.6.0.1"
VPN_SUBNET="10.6.0.0/24"

# Приоритеты ip rule (меньше = раньше).
#   100   — локальный трафик подсети -> main
#   110   — временное правило проверки связи в режиме обхода
#   30000 — обход VPN из панели
#   32765 — цепочка tun0 -> tun1
PREF_LOCAL=100
# Правило проверки связи обязано стоять НИЖЕ локального (100), а не выше.
# Оно задаётся как 'from <адрес шлюза>' без ограничения по назначению —
# с приоритетом 90 под него попадали бы и ответы сервера клиентам
# (источник 10.6.0.1, назначение 10.6.0.x) и уходили бы в туннель.
# Пока второй VPN лежит, клиенты теряли бы доступ к панели и к шлюзу.
PREF_PROBE=110
PREF_BYPASS=30000
PREF_CHAIN=32765

# ═══════════════════════════════════════════════════════
# ЛОГИРОВАНИЕ
# ═══════════════════════════════════════════════════════

rotate() {
    local f="$1" sz
    [ -f "$f" ] || return
    sz=$(stat -c%s "$f" 2>/dev/null || echo 0)
    if [ "$sz" -gt "$MAX_LOG" ]; then
        tail -n "$KEEP_LINES" "$f" > "${f}.tmp" 2>/dev/null && mv -f "${f}.tmp" "$f"
    fi
}

log() {
    mkdir -p "$LOG_DIR" 2>/dev/null
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$1] [демон] $2" >> "$LOG"
    chmod 664 "$LOG" 2>/dev/null || true
    logger -t "OVPNPlus" "[$1] $2"
    rotate "$LOG"
}

# ═══════════════════════════════════════════════════════
# ПОДСЕТЬ tun0 — динамически, без хардкода
# ═══════════════════════════════════════════════════════

ip_to_int() {
    local a b c d
    IFS=. read -r a b c d <<< "$1"
    echo $(( (a << 24) + (b << 16) + (c << 8) + d ))
}

int_to_ip() {
    local i=$1
    echo "$(( (i >> 24) & 255 )).$(( (i >> 16) & 255 )).$(( (i >> 8) & 255 )).$(( i & 255 ))"
}

# Маска 255.255.255.0 -> длина префикса 24.
mask_to_prefix() {
    local m i bits=0 expected
    m=$(ip_to_int "$1")
    for (( i = 31; i >= 0; i-- )); do
        if (( (m >> i) & 1 )); then bits=$((bits + 1)); else break; fi
    done
    # Маска обязана быть сплошной: 255.255.0.255 иначе дала бы 16
    # и молча увела демона управлять чужой подсетью.
    if [ "$bits" -eq 0 ]; then expected=0; else expected=$(( (0xFFFFFFFF << (32 - bits)) & 0xFFFFFFFF )); fi
    [ "$m" -eq "$expected" ] || { echo "-1"; return 1; }
    echo "$bits"
}

# Читает 'server <сеть> <маска>' из server.conf и вычисляет подсеть.
# OpenVPN, в отличие от WireGuard, задаёт сеть маской, а не префиксом —
# поэтому маску переводим в длину префикса честной битовой арифметикой.
load_server_net() {
    local line net mask prefix
    [ -f "$SRV_CONF" ] || { log "WARN" "Не найден $SRV_CONF — использую подсеть по умолчанию $VPN_SUBNET"; return; }

    line=$(grep -E '^[[:space:]]*server[[:space:]]+[0-9.]+[[:space:]]+[0-9.]+' "$SRV_CONF" 2>/dev/null | head -1)
    [ -z "$line" ] && { log "WARN" "В $SRV_CONF нет директивы server — использую $VPN_SUBNET"; return; }

    net=$(echo "$line"  | awk '{print $2}')
    mask=$(echo "$line" | awk '{print $3}')

    if ! [[ "$net" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || ! [[ "$mask" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
        log "WARN" "Некорректная директива server в $SRV_CONF — использую $VPN_SUBNET"
        return
    fi

    prefix=$(mask_to_prefix "$mask")
    if [ "$prefix" -lt 8 ] || [ "$prefix" -gt 30 ]; then
        # CRIT, а не WARN: с подсетью по умолчанию демон будет управлять
        # правилами и NAT чужой сети, цепочка молча перестанет работать,
        # а клиенты пойдут напрямую — без единого признака аварии.
        log "CRIT" "Некорректная маска $mask в $SRV_CONF — остаюсь на $VPN_SUBNET, цепочка работать не будет"
        return
    fi

    local new_subnet="${net}/${prefix}"
    local new_gw
    new_gw=$(int_to_ip $(( $(ip_to_int "$net") + 1 )))

    if [ "$new_subnet" != "$VPN_SUBNET" ]; then
        log "INFO" "Подсеть клиентов: $new_subnet (шлюз $new_gw)"
    fi
    VPN_GW="$new_gw"
    VPN_SUBNET="$new_subnet"
}

# ═══════════════════════════════════════════════════════
# STATE
# ═══════════════════════════════════════════════════════

read_state() {
    VPN_STATE="running"
    BUSY_SINCE=0
    [ -f "$STATE_FILE" ] || return
    local line
    while IFS= read -r line; do
        case "$line" in
            STATE=*)      VPN_STATE="${line#STATE=}" ;;
            BUSY_SINCE=*) BUSY_SINCE="${line#BUSY_SINCE=}" ;;
        esac
    done < "$STATE_FILE"
    [[ "$BUSY_SINCE" =~ ^[0-9]+$ ]] || BUSY_SINCE=0
}

# Атомарно: tmp -> mv -> chmod. chmod ПОСЛЕ mv обязателен: mv переносит
# владельца tmp-файла (root, umask 022 => 0644), и www-data теряет запись.
save_state() {
    local tmp="${STATE_FILE}.hc.tmp"
    if printf 'STATE=%s\nBUSY_SINCE=0\n' "$1" > "$tmp" 2>/dev/null && mv -f "$tmp" "$STATE_FILE"; then
        chown root:www-data "$STATE_FILE" 2>/dev/null || true
        chmod 664 "$STATE_FILE" 2>/dev/null || true
    else
        rm -f "$tmp" 2>/dev/null
    fi
}

# ═══════════════════════════════════════════════════════
# ПРОВЕРКИ
# ═══════════════════════════════════════════════════════

config_exists() { [ -s "$UP_CONFIG" ]; }
iface_exists()  { ip link show "$INTERFACE" &>/dev/null; }
iface_has_ip()  { ip -4 addr show "$INTERFACE" 2>/dev/null | grep -q "inet "; }

load_nic() {
    WAN_IF=""
    [ -f "$NIC_FILE" ] && WAN_IF=$(head -1 "$NIC_FILE" 2>/dev/null | tr -d '[:space:]')
    if [ -z "$WAN_IF" ] || ! ip link show "$WAN_IF" &>/dev/null; then
        WAN_IF=$(ip route show default 2>/dev/null | grep -v 'dev tun\|dev wg' | grep -oP 'dev \K\S+' | head -1)
    fi
    [ -z "$WAN_IF" ] && log "WARN" "Не удалось определить WAN-интерфейс"
}

# Параллельный ping по пяти хостам — один заблокированный адрес
# не должен давать ложный вердикт «VPN лежит».
# Возврат по ПЕРВОМУ успеху, а не по завершении всех пяти: если хотя бы
# один адрес из списка не маршрутизируется (9.9.9.9 и 1.0.0.1 режут довольно
# часто), ожидание всех процессов стоило бы полный таймаут даже при живом
# туннеле — период цикла удваивался бы на ровном месте.
ping_via() {
    local via="$1"
    [ -z "$via" ] && return 1
    local flag
    flag=$(mktemp /tmp/ovpnhc.XXXXXX) || return 1
    rm -f "$flag"

    local h pids=()
    for h in "${PING_HOSTS[@]}"; do
        ( ping -n -c 1 -W "$PING_TIMEOUT" -I "$via" "$h" &>/dev/null && : > "$flag" ) &
        pids+=($!)
    done

    local waited=0 limit=$(( PING_TIMEOUT * 10 + 3 )) p alive
    while [ "$waited" -lt "$limit" ]; do
        if [ -f "$flag" ]; then
            kill "${pids[@]}" 2>/dev/null
            wait "${pids[@]}" 2>/dev/null
            rm -f "$flag"
            return 0
        fi

        # Выходим, только когда завершились ВСЕ процессы.
        # 'kill -0' со списком PID возвращает ошибку, если умер хотя бы один,
        # поэтому проверять их одной командой нельзя: первый же быстро
        # отвалившийся ping оборвал бы ожидание, пока остальные ещё работают.
        alive=0
        for p in "${pids[@]}"; do
            if kill -0 "$p" 2>/dev/null; then alive=1; break; fi
        done
        [ "$alive" -eq 0 ] && break

        sleep 0.1
        waited=$((waited + 1))
    done

    kill "${pids[@]}" 2>/dev/null
    wait "${pids[@]}" 2>/dev/null
    if [ -f "$flag" ]; then rm -f "$flag"; return 0; fi
    rm -f "$flag"
    return 1
}

ping_wan() {
    [ -z "$WAN_IF" ] && return 0
    ping_via "$WAN_IF"
}

# Связь через туннель: ping с адреса шлюза — тот же путь, что у клиентов.
ping_tunnel() { ping_via "$VPN_GW"; }

# Запасная проверка канала данных, когда ICMP не проходит.
#
# ЗАЧЕМ: часть провайдеров второго VPN режет ICMP целиком, и тогда ping
# не докажет ничего — туннель работает, а демон считал бы его мёртвым
# и бесконечно перезапускал исправное соединение.
#
# ПОЧЕМУ ИМЕННО ТАК, А НЕ ПО ЖУРНАЛУ СЛУЖБЫ: у WireGuard есть latest-handshake,
# точный признак того, что пир отвечает. У OpenVPN такого счётчика нет, и
# соблазнительно смотреть в журнал на «Initialization Sequence Completed».
# Но эта строка доказывает лишь, что НАШ клиент дошёл до конца инициализации.
# Апстрим, который принимает TLS, но не пропускает трафик (кончился трафик
# у продавца, сломан их NAT), переподключается по keepalive примерно раз
# в минуту и печатает её снова и снова — то есть выглядел бы живым вечно.
#
# curl --interface от root использует SO_BINDTODEVICE: пакет уходит прямо
# в tun1 мимо таблиц маршрутизации. Это проверка именно канала данных.
# Только IP-адреса, без доменных имён: DNS сервера может смотреть куда угодно
# (в том числе во второй VPN), и разрешение имени добавило бы к проверке
# собственный таймаут, ничего не проверяя по существу.
HTTP_PROBE_IPS=("1.1.1.1" "8.8.8.8")

# ВАЖНО: привязываемся к АДРЕСУ ШЛЮЗА, а не к имени интерфейса.
#
# 'curl --interface tun1' не обходит маршрутизацию: SO_BINDTODEVICE лишь
# ограничивает поиск маршрута этим устройством, а сам поиск идёт как обычно.
# Маршрут по умолчанию через tun1 лежит ТОЛЬКО в таблице 120, попасть в неё
# можно исключительно по правилу 'from <подсеть клиентов>', и в main его нет
# (служба запускается с --route-nopull). Такой запрос упёрся бы в ENETUNREACH,
# то есть проверка всегда была бы отрицательной.
#
# С адресом шлюза (он лежит в подсети клиентов) пакет попадает под то же
# правило policy routing, что и трафик клиентов, — ровно как в ping_tunnel.
http_via_gw() {
    local ip
    iface_exists || return 1
    for ip in "${HTTP_PROBE_IPS[@]}"; do
        curl -sS --connect-timeout 2 --max-time 2 --interface "$VPN_GW" \
             -o /dev/null "http://${ip}" 2>/dev/null && return 0
    done
    return 1
}

# Сколько секунд назад туннель отчитался о подключении (-1 если не было).
# Только для диагностики — показать в журнале, как давно туннель молчит.
# --since ограничивает разбор: без него сканировался бы весь журнал юнита.
init_age() {
    local ts now
    ts=$(journalctl -u "$UPSTREAM_SVC" --since "-6 hours" --no-pager -o short-unix 2>/dev/null \
         | grep "Initialization Sequence Completed" | tail -1 | cut -d. -f1)
    [[ "$ts" =~ ^[0-9]+$ ]] || { echo "-1"; return 1; }
    now=$(date +%s)
    echo $((now - ts))
}

# Проверка связи, корректная В ЛЮБОМ режиме.
#
# ПРОБЛЕМА, КОТОРУЮ РЕШАЕТ:
# в режиме обхода правило 'from <подсеть> table 120' снято, поэтому обычный
# ping с адреса шлюза уходит через NIC и ВСЕГДА успешен — даже когда второй
# VPN выключен. Демон решал бы «туннель ожил», возвращал правило, связь снова
# падала — и так по кругу каждые несколько секунд.
#
# Решение: на время проверки добавляем узкое правило только для адреса шлюза
# (один /32) с приоритетом PREF_PROBE. Клиентский трафик не затрагивается:
# у клиентов другие адреса, они продолжают идти напрямую.
#
# Аргумент 'deep' включает запасную проверку по HTTP.
#
# Она стоит до четырёх секунд, поэтому в первой из двух попыток не нужна:
# одиночная потеря пакета отсеивается повторным пингом, и платить за неё
# лишними секундами незачем. Глубокая проверка идёт во второй попытке,
# когда решается вопрос о переключении, а также при подъёме туннеля.
probe_tunnel() {
    local deep="${1:-}"
    local rc=1 added=0

    # Временное правило ставим ОДИН раз на всю проверку — и ping, и HTTP
    # обязаны идти по одному и тому же пути. Если снять его сразу после
    # ping, запасная проверка ушла бы уже мимо туннеля и была бы успешна
    # при мёртвом апстриме.
    if [ "${CHAIN_BYPASSED:-0}" -eq 1 ]; then
        ip rule add from "$VPN_GW" table "$TABLE_ID" preference "$PREF_PROBE" 2>/dev/null && added=1
    fi

    if ping_tunnel; then
        rc=0
    elif [ "$deep" = "deep" ] && http_via_gw; then
        rc=0
    fi

    [ "$added" -eq 1 ] && \
        ip rule del from "$VPN_GW" table "$TABLE_ID" preference "$PREF_PROBE" 2>/dev/null

    return "$rc"
}

# Запись причины падения без спама: одна и та же причина попадает в журнал
# не чаще раза в DOWN_DEDUP секунд. Без этого при длительной аварии журнал
# забивается одной строкой каждые несколько секунд.
note_down() {
    local reason="$1" now age
    now=$(date +%s)
    if [ "$reason" = "$LAST_DOWN_REASON" ] && [ $((now - LAST_DOWN_AT)) -lt "$DOWN_DEDUP" ]; then
        return 0
    fi
    LAST_DOWN_REASON="$reason"
    LAST_DOWN_AT=$now
    age=$(init_age)
    if [ "$age" -ge 0 ] 2>/dev/null; then
        log "WARN" "Туннель не работает: ${reason} (последнее подключение ${age}с назад)"
    else
        log "WARN" "Туннель не работает: ${reason} (подключения не было вовсе)"
    fi
}

has_chain_rule() {
    ip rule show 2>/dev/null | grep -q "from ${VPN_SUBNET} lookup \(ovpnchain\|${TABLE_ID}\)"
}

# Правило, выводящее локальный трафик подсети из-под цепочки.
# Без него ответ сервера клиенту (источник = адрес шлюза, он В подсети)
# попадает под 'from <подсеть> table 120' и уходит в tun1. Ломает панель
# на адресе шлюза, ping шлюза и трафик между клиентами.
has_local_rule() {
    ip rule show 2>/dev/null | grep -q "to ${VPN_SUBNET} lookup main"
}

# Проверка ПОРЯДКА правил, а не только их наличия.
# Локальное правило ОБЯЗАНО иметь номер МЕНЬШЕ, чем цепочка — иначе оно
# бесполезно. Такое бывает, если цепочку добавили без preference после
# локального правила: тогда она получает номер выше и перехватывает всё.
rules_order_ok() {
    local loc chain
    loc=$(ip rule show 2>/dev/null   | grep "to ${VPN_SUBNET} lookup main" | head -1 | cut -d: -f1)
    chain=$(ip rule show 2>/dev/null | grep "from ${VPN_SUBNET} lookup \(ovpnchain\|${TABLE_ID}\)" | head -1 | cut -d: -f1)

    # Цепочки нет — упорядочивать нечего, и это штатное состояние режима
    # direct. Возвращать здесь «неверный порядок» значило бы звать
    # fix_rules_order на каждой итерации: он писал бы тревожный WARN
    # на исправной системе и ставил правило цепочки при отсутствующем
    # втором VPN.
    [ -z "$chain" ] && return 0
    [ -z "$loc" ] && return 1

    [ "$loc" -lt "$chain" ]
}

fix_rules_order() {
    log "WARN" "Правила маршрутизации в неверном порядке — переставляю"
    while ip rule show 2>/dev/null | grep -q "from ${VPN_SUBNET} lookup \(ovpnchain\|${TABLE_ID}\)"; do
        ip rule del from "$VPN_SUBNET" table "$TABLE_ID" 2>/dev/null || break
    done
    while ip rule show 2>/dev/null | grep -q "to ${VPN_SUBNET} lookup main"; do
        ip rule del to "$VPN_SUBNET" lookup main 2>/dev/null || break
    done
    ip rule add to   "$VPN_SUBNET" lookup main      preference "$PREF_LOCAL" 2>/dev/null
    ip rule add from "$VPN_SUBNET" table "$TABLE_ID" preference "$PREF_CHAIN" 2>/dev/null
    if rules_order_ok; then
        log "OK" "Порядок правил восстановлен (${PREF_LOCAL} локальный, ${PREF_CHAIN} цепочка)"
    else
        log "ERR" "Не удалось восстановить порядок правил"
    fi
}

has_chain_route() {
    ip route show table "$TABLE_ID" 2>/dev/null | grep -q "^default.*dev ${INTERFACE}"
}

# Маршрут по умолчанию для таблицы цепочки ставит ИМЕННО ДЕМОН, а не хук
# OpenVPN.
#
# ПОЧЕМУ ТАК: у WireGuard эквивалент делается директивой PostUp внутри конфига.
# У OpenVPN аналог — --route-up, но чтобы он сработал, службе нужен ключ
# --script-security 2. А он разрешает выполнение скриптов И ИЗ САМОГО КОНФИГА
# тоже. Конфиг присылает поставщик второго VPN и записывает панель — то есть
# ключ открывал бы прямой путь к выполнению команд от root.
#
# Здесь маршрут ставит демон (root:root, панель его изменить не может),
# а служба запускается вообще без script-security. Директивы up/down в чужом
# конфиге тогда не работают в принципе, а не только после вырезания.
#
# 'dev tun1' без via корректно для tun-устройства: это point-to-point,
# пакет просто записывается в туннель.
ensure_chain_route() {
    iface_exists || return 1
    has_chain_route && return 0
    ip route replace default dev "$INTERFACE" table "$TABLE_ID" 2>/dev/null
}

# Self-heal без перезапуска VPN: сетевые события (carrier down на WAN,
# рестарт networking) сносят правила, а служба их назад не ставит.
heal_chain() {
    local healed=0
    # ПОРЯДОК ВАЖЕН: сначала локальное правило, оно должно срабатывать
    # раньше правила цепочки.
    if ! has_local_rule; then
        log "WARN" "Потеряно правило 'to ${VPN_SUBNET} lookup main' — восстанавливаю"
        ip rule add to "$VPN_SUBNET" lookup main preference "$PREF_LOCAL" 2>/dev/null && healed=1
    fi
    # В режиме временного обхода правило цепочки снято НАМЕРЕННО —
    # восстанавливать его здесь значит сразу же снова отобрать интернет.
    if [ "${CHAIN_BYPASSED:-0}" -eq 0 ] && ! has_chain_rule; then
        log "WARN" "Потеряно правило цепочки (from ${VPN_SUBNET} table ${TABLE_ID}) — восстанавливаю"
        # preference ЗАДАЁМ ЯВНО: без него ip rule берёт номер на 1 меньше
        # текущего минимума и окажется ВЫШЕ локального правила — тогда цепочка
        # перехватит локальный трафик и сломает панель и связь между клиентами.
        ip rule add from "$VPN_SUBNET" table "$TABLE_ID" preference "$PREF_CHAIN" 2>/dev/null && healed=1
    fi
    if iface_exists && ! has_chain_route; then
        log "WARN" "Нет default route в таблице ${TABLE_ID} — ставлю"
        ensure_chain_route && healed=1
    fi
    [ "$healed" -eq 1 ] && log "OK" "Цепочка маршрутизации восстановлена без перезапуска VPN"
}

# ══════════════════════════════════════════════════════
# ВРЕМЕННЫЙ ОБХОД — работа напрямую, пока второй VPN лежит
# ══════════════════════════════════════════════════════
#
# ПОЧЕМУ НЕДОСТАТОЧНО ПРАВИЛ iptables:
# правило 'from <подсеть> table 120' забирает весь трафик клиентов в таблицу 120,
# а там default ведёт в tun1. Если интерфейс есть, но связи нет — пакеты просто
# гибнут в нём. До FORWARD дело вообще не доходит, поэтому разрешающее правило
# 'tun0 -> NIC ACCEPT' ничего не даёт: клиенты сидят без интернета всё время,
# пока демон перезапускает туннель.
#
# Решение: снять само правило — тогда трафик проваливается в main-таблицу
# и уходит через NIC. Когда туннель оживёт — правило возвращается.

enter_failover() {
    # ИДЕМПОТЕНТНО: смотрим на ФАКТИЧЕСКОЕ наличие правила, а не на флаг.
    # Правило могло вернуться помимо нас — например, heal_chain успел
    # отработать между итерациями. С проверкой по флагу мы бы решили
    # «уже в обходе» и клиенты снова остались бы без интернета.
    if has_chain_rule; then
        # Удаляем БЕЗ указания preference: условие ловит правило с любым
        # приоритетом, а удаление по фиксированному оставило бы правило
        # с другим номером на месте. Тогда '|| break' молча выходил бы,
        # флаг всё равно выставлялся, и клиенты остались бы без интернета
        # без единой попытки это исправить.
        local i=0
        while [ "$i" -lt 20 ] && has_chain_rule; do
            ip rule del from "$VPN_SUBNET" table "$TABLE_ID" 2>/dev/null || break
            i=$((i + 1))
        done

        if has_chain_rule; then
            log "ERR" "Не удалось снять правило цепочки — клиенты останутся без интернета"
            return 1
        fi

        [ "${CHAIN_BYPASSED:-0}" -eq 0 ] && \
            log "WARN" "Второй VPN недоступен — клиенты временно выходят напрямую через этот сервер"
    fi
    CHAIN_BYPASSED=1
    write_health
}

exit_failover() {
    [ "${CHAIN_BYPASSED:-0}" -eq 0 ] && return 0
    ip rule add from "$VPN_SUBNET" table "$TABLE_ID" preference "$PREF_CHAIN" 2>/dev/null
    CHAIN_BYPASSED=0
    log "OK" "Второй VPN вернулся — трафик снова идёт через него"
    write_health
}

# ══════════════════════════════════════════════════════
# ПРИЗНАК СОСТОЯНИЯ ДЛЯ ПАНЕЛИ
# ══════════════════════════════════════════════════════
#
# ЗАЧЕМ: панель определяет состояние туннеля наличием интерфейса. В режиме
# обхода интерфейс есть, поэтому без этого файла она показывала бы
# «Подключено» ровно тогда, когда трафик идёт напрямую с адреса сервера —
# единственный индикатор для владельца врал бы в момент аварии.
#
# Пишем атомарно (tmp -> rename), chmod после rename: rename переносит
# владельца временного файла.
write_health() {
    local tmp="${HEALTH_FILE}.tmp"
    {
        printf 'TUNNEL=%s\n' "${vpn_ok:-0}"
        printf 'BYPASS=%s\n' "${CHAIN_BYPASSED:-0}"
        printf 'AT=%s\n' "$(date +%s)"
    } > "$tmp" 2>/dev/null || { rm -f "$tmp" 2>/dev/null; return; }
    mv -f "$tmp" "$HEALTH_FILE" 2>/dev/null || { rm -f "$tmp" 2>/dev/null; return; }
    chown root:www-data "$HEALTH_FILE" 2>/dev/null || true
    chmod 664 "$HEALTH_FILE" 2>/dev/null || true
}

# ВАЖЕН ПОРЯДОК: ping с source-адресом шлюза идёт тем же путём, что и трафик
# клиентов. Но если default route в таблице 120 пропал, пакет проваливается
# в main, уходит через NIC под MASQUERADE и ping УСПЕШЕН при мёртвом туннеле —
# ложноположительный результат и одновременно реальная утечка. Поэтому
# маршрут проверяется ПЕРВЫМ.
tunnel_alive() {
    iface_exists || return 1
    iface_has_ip || return 1
    has_chain_route || return 1
    probe_tunnel "${1:-}"
}

# ═══════════════════════════════════════════════════════
# РЕЖИМЫ МАРШРУТИЗАЦИИ КЛИЕНТСКОГО ТРАФИКА
# ═══════════════════════════════════════════════════════
#
# ДВА РЕЖИМА, переключаются АВТОМАТИЧЕСКИ по наличию конфига второго VPN:
#
#   direct — конфига НЕТ. Работаем как обычный OpenVPN-сервер: клиенты
#            выходят в интернет через NIC сервера.
#
#   chain  — конфиг есть. Трафик идёт через tun1. Выход через NIC закрыт,
#            если включён Kill Switch, иначе остаётся аварийным запасным
#            путём. Исключение — адреса из routes.txt (цепочка OVPNPLUS_BYPASS).
#
# Почему этим занимается демон: иначе www-data пришлось бы дать sudo на
# iptables — а это практически root. Панель только кладёт конфиг,
# а режим переключается сам в течение нескольких секунд.

killswitch_enabled() {
    # По умолчанию ВЫКЛЮЧЕН: если второй VPN ляжет, клиенты продолжат
    # работать через этот сервер. Остаться без интернета вообще хуже
    # для большинства сценариев, чем временно выйти с другого адреса.
    [ -f "$SETTINGS_FILE" ] || return 1
    grep -q '^killswitch=true$' "$SETTINGS_FILE"
}

iptables_available() { command -v iptables >/dev/null 2>&1; }

# Сносит ВСЕ наши правила FORWARD — перед установкой нового режима.
# while — потому что от предыдущих запусков могли остаться дубли.
clear_forward_rules() {
    [ -z "$WAN_IF" ] && return 0
    while iptables -C FORWARD -i "$SERVER_IFACE" -o "$INTERFACE" -j ACCEPT 2>/dev/null; do
        iptables -D FORWARD -i "$SERVER_IFACE" -o "$INTERFACE" -j ACCEPT 2>/dev/null || break
    done
    while iptables -C FORWARD -i "$INTERFACE" -o "$SERVER_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null; do
        iptables -D FORWARD -i "$INTERFACE" -o "$SERVER_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || break
    done
    while iptables -C FORWARD -i "$SERVER_IFACE" -j OVPNPLUS_BYPASS 2>/dev/null; do
        iptables -D FORWARD -i "$SERVER_IFACE" -j OVPNPLUS_BYPASS 2>/dev/null || break
    done
    while iptables -C FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null; do
        iptables -D FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null || break
    done
    while iptables -C FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j ACCEPT 2>/dev/null; do
        iptables -D FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j ACCEPT 2>/dev/null || break
    done
    while iptables -C FORWARD -i "$WAN_IF" -o "$SERVER_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null; do
        iptables -D FORWARD -i "$WAN_IF" -o "$SERVER_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || break
    done
}

desired_mode() {
    if config_exists; then echo "chain"; else echo "direct"; fi
}

mode_rules_ok() {
    # Пустой WAN_IF — это НЕ «всё в порядке»: политика FORWARD остаётся
    # ACCEPT, поэтому отсутствие правил означает «пропускать всё»,
    # и Kill Switch молча не работал бы.
    if [ -z "$WAN_IF" ]; then
        load_nic
        [ -z "$WAN_IF" ] && return 1
    fi
    case "$1" in
        direct)
            iptables -C FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j ACCEPT 2>/dev/null
            ;;
        chain)
            iptables -C FORWARD -i "$SERVER_IFACE" -o "$INTERFACE" -j ACCEPT 2>/dev/null || return 1
            if killswitch_enabled; then
                iptables -C FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null
            else
                # Проверяем ОБА условия. Если ограничиться проверкой ACCEPT,
                # то после «включил Kill Switch → выключил» правило REJECT
                # осталось бы висеть навсегда: режим считался бы верным,
                # и интернет не вернулся бы до удаления конфига.
                iptables -C FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null && return 1
                iptables -C FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j ACCEPT 2>/dev/null
            fi
            ;;
    esac
}

apply_forward_mode() {
    local mode="$1"
    if ! iptables_available; then
        log "ERR" "iptables не найден — правила FORWARD не настроены"
        return 1
    fi
    if [ -z "$WAN_IF" ]; then
        log "ERR" "WAN-интерфейс не определён — правила FORWARD не настроены"
        return 1
    fi

    iptables -N OVPNPLUS_BYPASS 2>/dev/null || true
    clear_forward_rules

    if [ "$mode" = "direct" ]; then
        iptables -A FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j ACCEPT
        iptables -A FORWARD -i "$WAN_IF" -o "$SERVER_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT
        log "OK" "Режим: прямой выход через ${WAN_IF} (второго конфига нет)"
    else
        iptables -A FORWARD -i "$SERVER_IFACE" -o "$INTERFACE" -j ACCEPT
        iptables -A FORWARD -i "$INTERFACE" -o "$SERVER_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT
        iptables -A FORWARD -i "$SERVER_IFACE" -j OVPNPLUS_BYPASS
        if killswitch_enabled; then
            # Обратное правило нужно и здесь: адреса из списка обхода ходят
            # через NIC, и без него их ответы не вернулись бы к клиентам,
            # если политика FORWARD окажется DROP (Docker, fail2ban).
            iptables -A FORWARD -i "$WAN_IF" -o "$SERVER_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT
            iptables -A FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable
            log "OK" "Режим: весь трафик через ${INTERFACE}, аварийный выход закрыт (Kill Switch)"
        else
            iptables -A FORWARD -i "$SERVER_IFACE" -o "$WAN_IF" -j ACCEPT
            iptables -A FORWARD -i "$WAN_IF" -o "$SERVER_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT
            log "OK" "Режим: весь трафик через ${INTERFACE}, при его падении — напрямую"
        fi
    fi

    CURRENT_MODE="$mode"
    iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
    BYPASS_HASH=""   # цепочку обхода пересоберём
    return 0
}

check_forward_mode() {
    iptables_available || return 0
    local now want
    now=$(date +%s)
    # Проверяем чаще остального: пользователь ждёт реакции сразу после
    # нажатия «Сохранить», а не через полминуты.
    [ $((now - LAST_KS_CHECK)) -lt 5 ] && return 0
    LAST_KS_CHECK=$now

    # Пустой WAN_IF раньше означал ранний выход отсюда — и проверка режима
    # не выполнялась вовсе. Пробуем определить интерфейс заново.
    if [ -z "$WAN_IF" ]; then
        load_nic
        [ -z "$WAN_IF" ] && { log "ERR" "WAN-интерфейс не определён — режим FORWARD не проверяется"; return 1; }
    fi

    want=$(desired_mode)
    if [ "$want" != "$CURRENT_MODE" ]; then
        log "INFO" "Смена режима: ${CURRENT_MODE:-неизвестен} -> ${want}"
        apply_forward_mode "$want"
    elif ! mode_rules_ok "$want"; then
        log "WARN" "Правила FORWARD для режима '${want}' потеряны — восстанавливаю"
        apply_forward_mode "$want"
    fi
}

# Синхронизация списка обхода. Пересобираем только при изменении файла —
# иначе дёргали бы iptables каждые несколько секунд.
#
# Обход состоит из ДВУХ частей, и обе обязательны:
#
#   1. ip rule 'to <адрес> table main preference 30000' — отправляет трафик
#      к адресу в основную таблицу. Preference меньше, чем у цепочки (32765),
#      поэтому срабатывает раньше и пакет уходит напрямую, а не в туннель.
#
#   2. правило в цепочке OVPNPLUS_BYPASS — при включённом Kill Switch выход
#      через NIC закрыт REJECT'ом, и без явного ACCEPT обход тоже блокировался
#      бы. То есть одного ip rule недостаточно.
#
# Демон владеет обеими частями, поэтому список переживает перезагрузку сам,
# без дублирования состояния в конфигурационные файлы.
sync_bypass() {
    local now hash ip count=0
    iptables_available || return 0
    now=$(date +%s)
    # Пять секунд: панель больше не применяет правила обхода сама (это
    # убрало из sudoers шаблон 'ip rule add to *'), поэтому задержка здесь —
    # ровно то время, через которое пользователь увидит результат.
    [ $((now - LAST_BYPASS_SYNC)) -lt 5 ] && return 0
    LAST_BYPASS_SYNC=$now

    # 'none' вместо пустой строки: при отсутствующем routes.txt md5sum
    # выдаёт пустой вывод, равный начальному значению BYPASS_HASH —
    # и первый проход вышел бы рано, оставив старые правила от прошлого запуска.
    hash=$(md5sum "$ROUTES_FILE" 2>/dev/null | awk '{print $1}')
    [ -z "$hash" ] && hash="none"
    [ "$hash" = "$BYPASS_HASH" ] && return 0
    BYPASS_HASH="$hash"

    iptables -N OVPNPLUS_BYPASS 2>/dev/null || true
    iptables -F OVPNPLUS_BYPASS 2>/dev/null || true

    # Снимаем прошлые правила обхода целиком: их могло накопиться несколько,
    # а список мог измениться в любую сторону.
    local i=0
    while [ "$i" -lt 200 ] && ip rule del preference "$PREF_BYPASS" 2>/dev/null; do
        i=$((i + 1))
    done

    if [ -f "$ROUTES_FILE" ]; then
        while IFS= read -r ip || [ -n "$ip" ]; do
            ip=$(printf '%s' "$ip" | tr -d '[:space:]')
            [ -z "$ip" ] && continue
            # Файл пишет веб-панель — валидируем, не доверяем содержимому.
            [[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || continue
            iptables -A OVPNPLUS_BYPASS -d "$ip" -j ACCEPT 2>/dev/null
            ip rule add to "$ip" table main preference "$PREF_BYPASS" 2>/dev/null
            count=$((count + 1))
        done < "$ROUTES_FILE"
    fi

    log "INFO" "Список обхода синхронизирован: ${count} адресов"
    iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
}

# ГАРАНТИЯ ДОСТУПА к панели, SSH и порту OpenVPN.
#
# Требование: URL из lk.txt должен работать ВСЕГДА — и когда клиент подключён
# к VPN, и когда нет.
#
# Почему это нужно: apply_forward_mode и sync_bypass делают iptables-save
# в rules.v4. Если в этот момент INPUT-правила окажутся сбиты (iptables -F,
# чужой скрипт, ошибка админа) — сломанное состояние сохранится навсегда,
# и доступ к серверу будет потерян до консоли хостера.
ensure_access_rules() {
    iptables_available || return 0
    local now
    now=$(date +%s)
    [ $((now - LAST_ACCESS_CHECK)) -lt 30 ] && return 0
    LAST_ACCESS_CHECK=$now

    local changed=0

    if ! iptables -C INPUT -p tcp --dport "$PANEL_PORT" -j ACCEPT 2>/dev/null; then
        iptables -I INPUT -p tcp --dport "$PANEL_PORT" -j ACCEPT 2>/dev/null && changed=1
        log "WARN" "Восстановлен доступ к панели (tcp/${PANEL_PORT})"
    fi

    if ! iptables -C INPUT -p tcp --dport "$SSH_PORT" -j ACCEPT 2>/dev/null; then
        iptables -I INPUT -p tcp --dport "$SSH_PORT" -j ACCEPT 2>/dev/null && changed=1
        log "WARN" "Восстановлен доступ по SSH (tcp/${SSH_PORT})"
    fi

    # Порт OpenVPN берём из конфига — он случайный на каждой установке.
    local vpnport vpnproto
    vpnport=$(grep -oP '^\s*port\s+\K\d+' "$SRV_CONF" 2>/dev/null | head -1)
    vpnproto=$(grep -oP '^\s*proto\s+\K\w+' "$SRV_CONF" 2>/dev/null | head -1)
    vpnproto=${vpnproto:-udp}
    if [ -n "$vpnport" ] && ! iptables -C INPUT -p "$vpnproto" --dport "$vpnport" -j ACCEPT 2>/dev/null; then
        iptables -I INPUT -p "$vpnproto" --dport "$vpnport" -j ACCEPT 2>/dev/null && changed=1
        log "WARN" "Восстановлен порт OpenVPN (${vpnproto}/${vpnport})"
    fi

    # NAT и клампинг MSS — без них у клиентов просто нет интернета.
    # Установщик ставит их один раз; если правила снесли (iptables -F, чужой
    # скрипт, откат rules.v4), восстановить их больше некому.
    if ! iptables -t nat -C POSTROUTING -s "$VPN_SUBNET" -o "$WAN_IF" -j MASQUERADE 2>/dev/null; then
        [ -n "$WAN_IF" ] && iptables -t nat -A POSTROUTING -s "$VPN_SUBNET" -o "$WAN_IF" -j MASQUERADE 2>/dev/null && {
            changed=1; log "WARN" "Восстановлен NAT для ${VPN_SUBNET} через ${WAN_IF}"
        }
    fi
    if ! iptables -t nat -C POSTROUTING -s "$VPN_SUBNET" -o "$INTERFACE" -j MASQUERADE 2>/dev/null; then
        iptables -t nat -A POSTROUTING -s "$VPN_SUBNET" -o "$INTERFACE" -j MASQUERADE 2>/dev/null && {
            changed=1; log "WARN" "Восстановлен NAT для ${VPN_SUBNET} через ${INTERFACE}"
        }
    fi
    if ! iptables -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; then
        iptables -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null && {
            changed=1; log "WARN" "Восстановлен клампинг MSS"
        }
    fi

    [ "$changed" -eq 1 ] && iptables-save > /etc/iptables/rules.v4 2>/dev/null
    return 0
}

# ═══════════════════════════════════════════════════════
# УПРАВЛЕНИЕ
# ═══════════════════════════════════════════════════════

# Осиротевший = конфига нет, а интерфейс висит. Такое бывает, если служба
# была убита по SIGKILL: ядро не всегда успевает снести устройство, а также
# если панель не смогла довести до конца удаление конфига.
kill_orphan() {
    log "WARN" "Осиротевший интерфейс ${INTERFACE} (конфига нет) — удаляю"
    ip link delete dev "$INTERFACE" 2>/dev/null
    ip rule del from "$VPN_SUBNET" table "$TABLE_ID" 2>/dev/null
    ip route flush table "$TABLE_ID" 2>/dev/null
    local i=0
    while [ $i -lt 6 ]; do
        iface_exists || { log "OK" "Осиротевший интерфейс удалён"; return 0; }
        sleep 1; i=$((i + 1))
    done
    log "ERR" "Не удалось удалить интерфейс ${INTERFACE}"
    return 1
}

restart_tunnel() {
    log "INFO" "Перезапуск ${INTERFACE}..."
    # reset-failed обязателен: при серии неудачных попыток systemd упирается
    # в StartLimitBurst и дальше молча отказывается запускать юнит.
    systemctl reset-failed "$UPSTREAM_SVC" &>/dev/null || true
    systemctl restart "$UPSTREAM_SVC" &>/dev/null

    local i=0
    while [ $i -lt "$POLL_MAX" ]; do
        # ПЕРВЫМ ДЕЛОМ, до сна и до проверки: пока туннель не подтверждён,
        # клиенты должны оставаться на прямом канале, а не ждать конца
        # проверки связи.
        [ "${CHAIN_BYPASSED:-0}" -eq 1 ] && enter_failover

        sleep 1; i=$((i + 1))

        # Маршрут ставим сами, как только появился интерфейс: без него
        # tunnel_alive не пройдёт никогда, и подъём выглядел бы неудачей.
        ensure_chain_route

        # Дешёвые проверки на каждой итерации, полная проверка связи —
        # каждую третью. Полная стоит до нескольких секунд (пять ping плюс
        # запасной HTTP), и на каждой итерации она растягивала бы цикл
        # втрое: всё это время демон не читает состояние и не соблюдает
        # флаг busy, выставленный панелью.
        if iface_exists && iface_has_ip && has_chain_route; then
            if [ $((i % 3)) -eq 0 ]; then
                if probe_tunnel deep; then
                    log "OK" "${INTERFACE} восстановлен (попыток: ${i})"
                    return 0
                fi
            fi
        fi
    done
    log "WARN" "${INTERFACE} не поднялся за ${POLL_MAX} попыток"
    return 1
}

do_recovery() {
    local now
    now=$(date +%s)
    [ "$COOLDOWN_UNTIL" -gt 0 ] && [ "$now" -lt "$COOLDOWN_UNTIL" ] && return 1

    if restart_tunnel; then
        # now берём заново: restart_tunnel мог занять десятки секунд, и по
        # старому значению исправное восстановление засчитывалось бы
        # как flapping с лишним cooldown.
        now=$(date +%s)
        if [ "$LAST_OK" -gt 0 ] && [ $((now - LAST_OK)) -lt 120 ]; then
            COOLDOWN=$COOLDOWN_INITIAL
            COOLDOWN_UNTIL=$((now + COOLDOWN_INITIAL))
            log "WARN" "Частые перезапуски (flapping) — cooldown ${COOLDOWN_INITIAL}с"
        else
            COOLDOWN=0; COOLDOWN_UNTIL=0
        fi
        LAST_OK=$(date +%s)
        return 0
    fi

    COOLDOWN=$((COOLDOWN + COOLDOWN_INITIAL))
    [ "$COOLDOWN" -gt "$COOLDOWN_MAX" ] && COOLDOWN=$COOLDOWN_MAX
    COOLDOWN_UNTIL=$(( $(date +%s) + COOLDOWN ))
    log "INFO" "Cooldown ${COOLDOWN}с до следующей попытки"
    return 1
}

# ═══════════════════════════════════════════════════════
# ГЛАВНЫЙ ЦИКЛ
# ═══════════════════════════════════════════════════════

main_loop() {
    COOLDOWN=0; COOLDOWN_UNTIL=0; LAST_OK=0; LAST_RELOAD=0
    LAST_DOWN_REASON=""; LAST_DOWN_AT=0; CHAIN_BYPASSED=0
    LAST_KS_CHECK=0; LAST_BYPASS_SYNC=0; BYPASS_HASH=""; LAST_ACCESS_CHECK=0; CURRENT_MODE=""
    SRV_LAST_TRY=0
    WAN_STATE="ok"; WAN_DOWN_SINCE=0; vpn_ok=0

    mkdir -p "$LOG_DIR" 2>/dev/null
    load_server_net
    load_nic
    LAST_RELOAD=$(date +%s)
    log "INFO" "OVPNPlus Health Check запущен (подсеть=${VPN_SUBNET}, WAN=${WAN_IF:-неизвестен})"

    # Режим выставляем СРАЗУ на старте, до warmup: если туннель после
    # загрузки не поднимется, трафик клиентов не должен утечь наружу
    # в этот промежуток.
    apply_forward_mode "$(desired_mode)" || log "CRIT" "Не удалось настроить правила FORWARD"
    sync_bypass

    # Доступ к панели проверяем сразу: если правила были сбиты до старта
    # демона — восстановим до того, как iptables-save зафиксирует поломку.
    ensure_access_rules

    # Локальное правило должно стоять, даже если второго VPN ещё нет: без него
    # сломается трафик между клиентами сразу, как появится правило цепочки.
    if ! has_local_rule; then
        ip rule add to "$VPN_SUBNET" lookup main preference "$PREF_LOCAL" 2>/dev/null \
            && log "OK" "Добавлено правило 'to ${VPN_SUBNET} lookup main'"
    fi
    rules_order_ok || fix_rules_order

    trap 'log "INFO" "Демон остановлен"; exit 0' TERM INT

    # WARMUP: служба туннеля и демон стартуют параллельно, а TLS-рукопожатие
    # OpenVPN занимает 5-20с. Без паузы демон увидит «нет связи» и начнёт
    # перезапускать исправный туннель.
    if config_exists; then
        local warmup_start elapsed=0
        warmup_start=$(date +%s)
        log "INFO" "Warmup: ждём стабилизации туннеля (до ${WARMUP_TIMEOUT}с)"
        while [ "$elapsed" -lt "$WARMUP_TIMEOUT" ]; do
            read_state
            [ "$VPN_STATE" = "stopped" ] && break
            config_exists || break
            ensure_chain_route
            if tunnel_alive deep; then
                log "OK" "Туннель стабилен через ${elapsed}с warmup"
                vpn_ok=1; LAST_OK=$(date +%s)
                break
            fi
            sleep 3
            elapsed=$(( $(date +%s) - warmup_start ))
        done
        [ "$vpn_ok" -eq 0 ] && log "WARN" "Туннель не поднялся за warmup — переходим в обычный режим"
    fi

    while true; do
        read_state

        # Режим маршрутизации и список обхода проверяем ПЕРВЫМ ДЕЛОМ,
        # до всех выходов из цикла.
        #
        # ПОЧЕМУ ИМЕННО ЗДЕСЬ: если поставить эти вызовы после проверок
        # busy/stopped с их `continue`, то в состоянии stopped (конфиг удалён,
        # туннеля нет) режим не проверялся бы вовсе. А именно там защита
        # нужна больше всего — туннеля нет совсем.
        #
        # Во время busy это тоже безопасно: мы трогаем только iptables, а не
        # службу туннеля, так что с операциями панели не конфликтуем.
        # routes.txt панель пишет атомарно (tmp -> rename), поэтому
        # недочитать половину файла нельзя.
        check_forward_mode
        sync_bypass
        ensure_access_rules

        # Признак состояния пишем БЕЗУСЛОВНО на каждой итерации.
        #
        # Записи только по переходам недостаточно: на исправном сервере
        # переходов не происходит вовсе, метка времени замерзает, и панель
        # начинает показывать «демон не отвечает» при рабочей системе.
        write_health

        # Жив ли САМ СЕРВЕР для клиентов — до всех выходов из цикла.
        #
        # Весь каскад ниже про tun1. Если упал tun0, ping -I <адрес шлюза>
        # падает мгновенно с «Cannot assign requested address», демон ставит
        # диагноз «нет связи через туннель» и до бесконечности перезапускает
        # исправный tun1, не трогая настоящую причину. В режиме без второго
        # VPN до проверок дело вообще не дошло бы, а упавший tun0 там точно
        # так же означает, что клиенты отключены.
        if ! ip link show "$SERVER_IFACE" &>/dev/null \
           || ! ip -4 addr show "$SERVER_IFACE" 2>/dev/null | grep -q "inet ${VPN_GW}\b"; then
            note_down "не поднят интерфейс ${SERVER_IFACE} — второй VPN ни при чём"
            vpn_ok=0
            # С паузой между попытками: без неё при по-настоящему битом
            # server.conf демон дёргал бы systemd каждые две секунды и упёрся
            # в его ограничение частоты запусков.
            now=$(date +%s)
            if [ $((now - SRV_LAST_TRY)) -ge 30 ]; then
                SRV_LAST_TRY=$now
                log "WARN" "Пробую поднять ${SERVER_IFACE}"
                systemctl start openvpn-server@server &>/dev/null
            fi
            sleep "$CHECK_INTERVAL"; continue
        fi

        # Панель занята — в туннель не вмешиваемся. Это и есть защита от гонки.
        if [ "$VPN_STATE" = "busy" ]; then
            now=$(date +%s)
            if [ "$BUSY_SINCE" -gt 0 ] && [ $((now - BUSY_SINCE)) -gt "$BUSY_STALE" ]; then
                log "WARN" "Флаг busy висит >${BUSY_STALE}с (панель упала?) — снимаю"
                save_state "running"
            fi
            sleep 2; continue
        fi

        # Осиротевший интерфейс убираем ДО ветки stopped.
        #
        # Панель ставит stopped даже когда снять туннель не удалось (это
        # честно пишется в журнал). Если выйти из цикла раньше проверки,
        # kill_orphan станет недостижим — а он единственный, кто снимает
        # правило цепочки и чистит таблицу 120. Итог: трафик клиентов
        # уходит в мёртвый интерфейс, и починить это может только
        # повторная загрузка конфига.
        if ! config_exists && iface_exists; then
            kill_orphan
        fi

        if [ "$VPN_STATE" = "stopped" ]; then
            vpn_ok=0
            sleep "$CHECK_INTERVAL"; continue
        fi

        # Конфига нет — мониторить нечего.
        if ! config_exists; then
            vpn_ok=0
            sleep "$CHECK_INTERVAL"; continue
        fi

        # Периодически перечитываем подсеть и WAN — их могли поменять
        # (переустановка сервера, смена интерфейса) без перезапуска демона.
        now=$(date +%s)
        if [ $((now - LAST_RELOAD)) -ge "$RELOAD_INTERVAL" ]; then
            load_server_net; load_nic; LAST_RELOAD=$now
        fi

        if [ "$WAN_STATE" = "down" ]; then
            if ping_wan; then
                local dur=$(( $(date +%s) - WAN_DOWN_SINCE ))
                log "OK" "Интернет на сервере восстановлен (был недоступен ${dur}с)"
                WAN_STATE="ok"; COOLDOWN=0; COOLDOWN_UNTIL=0
                if ! tunnel_alive; then
                    heal_chain
                    tunnel_alive || restart_tunnel
                fi
            else
                sleep "$CHECK_INTERVAL"
            fi
            continue
        fi

        # КАСКАД ПРОВЕРОК — каждая говорит, ЧТО именно сломалось.
        # Одна общая проверка давала бы в журнале вечное «нет связи»
        # без понимания причины.
        if ! iface_exists; then
            note_down "интерфейс ${INTERFACE} пропал"
            vpn_ok=0
            killswitch_enabled || enter_failover
            do_recovery && vpn_ok=1
            sleep "$CHECK_INTERVAL"; continue
        fi

        if ! iface_has_ip; then
            note_down "на интерфейсе нет IP-адреса"
            vpn_ok=0
            killswitch_enabled || enter_failover
            do_recovery && vpn_ok=1
            sleep "$CHECK_INTERVAL"; continue
        fi

        # Если Kill Switch включили в панели, пока действовал обход —
        # возвращаем правило сразу, не дожидаясь подъёма туннеля.
        [ "${CHAIN_BYPASSED:-0}" -eq 1 ] && killswitch_enabled && exit_failover

        # Маршрут в таблице цепочки нужен в любом режиме: в обходе снимается
        # только правило, а маршрут должен ждать возвращения трафика.
        ensure_chain_route

        # В режиме обхода правило цепочки снято намеренно — не считаем поломкой.
        if [ "${CHAIN_BYPASSED:-0}" -eq 0 ]; then
            if ! has_chain_rule || ! has_chain_route || ! has_local_rule; then
                heal_chain
            fi
            # Правила могут быть ВСЕ на месте, но в неверном порядке —
            # тогда локальное правило не работает, хотя и существует.
            rules_order_ok || fix_rules_order
        else
            # Локальное правило нужно в любом случае: без него ломается
            # связь между клиентами и доступ к панели по адресу шлюза.
            has_local_rule || ip rule add to "$VPN_SUBNET" lookup main preference "$PREF_LOCAL" 2>/dev/null
        fi

        # Две попытки с короткой паузой: одиночная потеря пакета не повод
        # для переключения, но и тянуть целую секунду незачем.
        # tunnel_alive, а НЕ голый probe_tunnel: маршрут в таблице 120
        # обязан проверяться ДО пинга. Если маршрут пропал, пакет
        # проваливается в main, уходит через NIC под MASQUERADE — и пинг
        # успешен при мёртвом туннеле. Демон рапортовал бы «Туннель
        # работает», пока трафик идёт с реального адреса сервера.
        if ! tunnel_alive; then
            sleep "$RETRY_DELAY"
            if ! tunnel_alive deep; then
                # Прежде чем винить туннель — жив ли интернет самого сервера?
                if ! ping_wan; then
                    log "WARN" "Интернет на сервере недоступен — туннель не трогаем, ждём"
                    WAN_STATE="down"; WAN_DOWN_SINCE=$(date +%s); vpn_ok=0
                    sleep "$CHECK_INTERVAL"; continue
                fi
                note_down "нет связи через туннель"
                vpn_ok=0
                # Kill Switch выключен — отдаём трафик напрямую, пока чиним.
                killswitch_enabled || enter_failover
                do_recovery && vpn_ok=1
                sleep "$CHECK_INTERVAL"; continue
            fi
        fi

        if [ "$vpn_ok" -eq 0 ]; then
            vpn_ok=1; LAST_OK=$(date +%s); COOLDOWN=0; COOLDOWN_UNTIL=0
            LAST_DOWN_REASON=""; LAST_DOWN_AT=0
            log "OK" "Туннель работает"
        fi

        # Туннель жив — возвращаем трафик в цепочку, если был обход.
        # Проверка стоит здесь, а не в начале цикла: возвращать трафик
        # в туннель можно только убедившись, что он реально работает.
        exit_failover

        sleep "$CHECK_INTERVAL"
    done
}

# flock — защита от двух одновременных экземпляров
exec 200>"$LOCK"
if ! flock -n 200; then
    echo "Другой экземпляр ovpn-healthcheck уже запущен" >&2
    exit 1
fi

main_loop
