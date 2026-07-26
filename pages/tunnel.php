<?php
/**
 * OVPNPlus — страница «Подключение».
 *
 * Показывает адрес второго VPN, к которому подключён сервер, живой пинг
 * до него и состояние. Одна кнопка удаления: сначала снимает туннель,
 * потом удаляет конфиг.
 */

require_once __DIR__ . '/../includes/ovp_helpers.php';

ovp_require_auth();
ovp_csrf_require();

$net = ovp_server_net();

$hasConfig  = file_exists(OVP_UP_CONF) && filesize(OVP_UP_CONF) > 0;
$up         = ovp_iface_exists();
$notice     = '';
$noticeKind = 'ok';

$remote = ovp_upstream_remote();
$host   = $remote['host'];
$port   = $remote['port'];

/**
 * Запускает службу второго VPN и ждёт появления интерфейса.
 *
 * reset-failed обязателен перед стартом: у юнита Restart=on-failure, и после
 * серии неудачных попыток systemd упирается в StartLimitBurst — дальше он
 * молча отказывается запускать службу вовсе, а кнопка «Подключить» перестаёт
 * работать до истечения окна или ручного вмешательства через SSH.
 */
function ovp_start_tunnel(): bool {
    ovp_sudo('systemctl reset-failed ' . OVP_UP_SVC);
    ovp_sudo('systemctl enable ' . OVP_UP_SVC);
    if (!ovp_sudo('systemctl start ' . OVP_UP_SVC)) {
        ovp_log('ERR', 'systemctl start ' . OVP_UP_SVC . ' завершился с ошибкой — проверьте правила sudo');
    }
    return ovp_wait_up(20);
}

// ══════════════════════════════════════════════════════════════
// ДЕЙСТВИЯ
// ══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
     * Снимаем ограничение времени выполнения на всё время операций с туннелем.
     *
     * Дефолт mod_php — 30 секунд. Загрузка конфига стоит до 28 секунд
     * (снятие туннеля плюс ожидание подъёма), а с откатом на предыдущий
     * конфиг — вдвое больше. PHP убивали бы посередине: флаг busy оставался
     * бы на диске, пользователь получал пустую страницу, а демон стоял бы
     * в стороне все три минуты до истечения BUSY_STALE.
     *
     * ignore_user_abort: закрытая вкладка не должна обрывать операцию
     * между «снял туннель» и «поднял новый».
     */
    @set_time_limit(0);
    @ignore_user_abort(true);

    if (isset($_POST['killswitch'])) {
        $want = $_POST['killswitch'] === 'on';
        if (ovp_killswitch_set($want)) {
            ovp_log('OK', $want
                ? 'Включён Kill Switch — при падении второго впн интернет будет отключён'
                : 'Выключен Kill Switch — при падении второго впн трафик пойдёт напрямую');
        }
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_POST['start'])) {
        ovp_state_set('busy');
        ovp_log('INFO', 'Запуск туннеля из панели');
        $ok = ovp_start_tunnel();
        // Ставим 'running' даже при неудаче: это НАМЕРЕНИЕ (туннель должен
        // работать), а не факт. Со статусом 'stopped' демон полностью
        // устраняется — и если второй VPN был недоступен ровно в эту секунду,
        // туннель никогда бы не поднялся без повторного нажатия вручную.
        ovp_state_set('running');
        ovp_log($ok ? 'OK' : 'WARN', $ok
            ? 'Туннель поднят'
            : 'Туннель пока не поднялся — мониторинг продолжит попытки');
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_POST['restart'])) {
        ovp_state_set('busy');
        ovp_log('INFO', 'Перезапуск туннеля из панели');
        ovp_bring_down();
        $ok = ovp_start_tunnel();
        ovp_state_set('running');
        ovp_log($ok ? 'OK' : 'WARN', $ok
            ? 'Туннель перезапущен'
            : 'Туннель пока не поднялся — мониторинг продолжит попытки');
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_POST['remove'])) {
        // ПОРЯДОК ВАЖЕН: сначала снимаем туннель, ПОТОМ удаляем конфиг.
        // Служба запускается с --config, и при живом процессе удалённый файл
        // ничего не меняет — но systemd с ConditionPathExists уже не поднимет
        // её обратно, а демон увидит осиротевший интерфейс. Снимаем явно.
        //
        // Флаг busy держит демона в стороне на всё время операции.
        ovp_state_set('busy');
        ovp_log('INFO', 'Отключение и удаление конфига второго VPN');

        $down = ovp_bring_down();

        ovp_sudo('systemctl disable ' . OVP_UP_SVC);
        if (file_exists(OVP_UP_CONF)) {
            @copy(OVP_UP_CONF, OVP_UP_BAK);
            @unlink(OVP_UP_CONF);
        }
        @unlink(OVP_UP_AUTH);

        ovp_state_set('stopped');
        ovp_log($down ? 'OK' : 'ERR', $down
            ? 'Конфиг удалён, туннель снят. Клиенты выходят напрямую через сервер'
            : 'Конфиг удалён, но интерфейс снять не удалось');
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_FILES['config']) && !empty($_FILES['config']['name'])) {
        $ext = strtolower(pathinfo($_FILES['config']['name'], PATHINFO_EXTENSION));

        if ($ext !== 'ovpn' && $ext !== 'conf') {
            $notice = 'Нужен файл с расширением .ovpn';
            $noticeKind = 'err';
            ovp_log('WARN', "Отклонён файл с расширением .$ext");

        } elseif (($_FILES['config']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $notice = 'Файл не загрузился. Попробуйте ещё раз';
            $noticeKind = 'err';

        } elseif (($_FILES['config']['size'] ?? 0) > 524288) {
            $notice = 'Файл больше 512 КБ — это не похоже на конфиг OpenVPN';
            $noticeKind = 'err';

        } else {
            $raw  = @file_get_contents($_FILES['config']['tmp_name']);
            $user = trim((string) ($_POST['vpn_user'] ?? ''));
            $pass = (string) ($_POST['vpn_pass'] ?? '');

            if ($raw === false || !preg_match('/^\s*remote\s+\S+/mi', $raw)) {
                $notice = 'Это не клиентский конфиг OpenVPN: нет строки remote';
                $noticeKind = 'err';
                ovp_log('WARN', 'Отклонён невалидный впн конфиг');

            } elseif (ovp_needs_credentials($raw) && ($user === '' || $pass === '')) {
                // Без файла с паролем OpenVPN ждёт ввода с консоли, и служба
                // зависает в запуске вместо понятной ошибки.
                $notice = 'Этот конфиг требует логин и пароль — заполните оба поля под окном загрузки.';
                $noticeKind = 'err';
                ovp_log('WARN', 'Отклонён впн конфиг: требуются логин и пароль');

            } elseif (preg_match('/[\r\n]/', $user . $pass)) {
                // Формат auth.txt — ровно две строки. Перенос внутри пароля
                // (частая история при вставке из менеджера паролей) сделал бы
                // из файла три строки: OpenVPN взял бы обрезок и отдал
                // AUTH_FAILED, а причина не диагностировалась бы вообще.
                $notice = 'Логин и пароль не должны содержать переносов строк. Проверьте, что скопировали их без лишних символов.';
                $noticeKind = 'err';
                ovp_log('WARN', 'Отклонены учётные данные с переносом строки');

            } elseif (($bad = ovp_addr_conflict($raw, $net)) !== '') {
                // Ранний отсев по статической директиве ifconfig. Основная
                // проверка — после подъёма туннеля: OpenVPN получает адрес
                // от сервера уже при подключении, и заранее его не узнать.
                $notice = 'Конфиг не подходит: адрес ' . $bad
                        . ' пересекается с подсетью ваших клиентов ' . $net['cidr']
                        . '. Попросите у продавца второго впн конфиг с другой адресацией.';
                $noticeKind = 'err';
                ovp_log('WARN', "Отклонён впн конфиг: адрес $bad пересекается с подсетью клиентов " . $net['cidr']);

            } else {
                ovp_state_set('busy');
                ovp_log('INFO', 'Установка впн конфига, подсеть клиентов ' . $net['cidr']);

                $hadPrevious = file_exists(OVP_UP_CONF);
                // Учётные данные копируем вместе с конфигом: без этого откат
                // возвращал прежний конфиг, но с новым (или отсутствующим)
                // паролем — и «вернули предыдущий рабочий» молча получал
                // AUTH_FAILED.
                $authBak = OVP_UP_AUTH . '.bak';
                @unlink($authBak);
                if ($hadPrevious) {
                    @copy(OVP_UP_CONF, OVP_UP_BAK);
                    if (file_exists(OVP_UP_AUTH)) @copy(OVP_UP_AUTH, $authBak);
                }

                ovp_bring_down();

                $withAuth = ($user !== '' && $pass !== '');
                if ($withAuth) {
                    // Права выставляем ДО записи содержимого: при umask 022
                    // файл иначе успевает существовать с режимом 0644.
                    @touch(OVP_UP_AUTH);
                    @chmod(OVP_UP_AUTH, 0640);
                    @file_put_contents(OVP_UP_AUTH, $user . "\n" . $pass . "\n", LOCK_EX);
                } else {
                    @unlink(OVP_UP_AUTH);
                }

                // Санитайзер отказывается работать с файлом, где не закрыт
                // inline-блок: в таком файле всё после открывающего тега
                // прошло бы мимо фильтра опасных директив.
                $prepared = null;
                try {
                    $prepared = ovp_prepare_config($raw, $withAuth);
                } catch (RuntimeException $e) {
                    // Туннель уже снят, а новый конфиг оказался негодным.
                    // Возвращаем предыдущий, иначе рабочий апстрим остался бы
                    // лежать из-за чужого битого файла, да ещё и с состоянием
                    // stopped — то есть демон не стал бы его поднимать.
                    @unlink(OVP_UP_AUTH);
                    if ($hadPrevious && file_exists(OVP_UP_BAK)) {
                        @copy(OVP_UP_BAK, OVP_UP_CONF);
                        @chmod(OVP_UP_CONF, 0640);
                        if (file_exists($authBak)) {
                            @copy($authBak, OVP_UP_AUTH);
                            @chmod(OVP_UP_AUTH, 0640);
                        }
                        ovp_start_tunnel();
                        ovp_state_set('running');
                        ovp_log('WARN', 'Конфиг отклонён, вернули предыдущий: ' . $e->getMessage());
                    } else {
                        ovp_state_set('stopped');
                        ovp_log('WARN', 'Отклонён впн конфиг: ' . $e->getMessage());
                    }
                    $notice = 'Файл повреждён: ' . $e->getMessage()
                            . '. Запросите конфиг у продавца второго впн заново.';
                    $noticeKind = 'err';
                }

                if ($prepared === null) {
                    // Конфиг отклонён — ничего не пишем и не запускаем.

                } elseif (@file_put_contents(OVP_UP_CONF, $prepared) === false) {
                    ovp_state_set('stopped');
                    $notice = 'Не удалось записать конфиг. Проверьте права на ' . OVP_UP_DIR;
                    $noticeKind = 'err';
                    ovp_log('ERR', 'Не удалось записать впн конфиг');

                } else {
                    @chmod(OVP_UP_CONF, 0640);
                    // Остаточный интерфейс уронит запуск с «File exists».
                    if (ovp_iface_exists()) {
                        ovp_sudo('ip link delete dev tun1');
                        usleep(500000);
                    }

                    $started = ovp_start_tunnel();

                    // Адрес туннеля известен только сейчас — до подключения
                    // OpenVPN его не знает. Если он попал в подсеть клиентов,
                    // в основной таблице окажутся два одинаковых маршрута,
                    // и после ближайшей перезагрузки клиенты станут
                    // недоступны без видимой связи с этим конфигом.
                    $clash = $started ? ovp_running_addr_conflict($net) : '';

                    if ($started && $clash === '') {
                        ovp_state_set('running');
                        ovp_log('OK', 'Впн конфиг установлен, туннель поднят');
                        header('Location: cabinet.php?menu=tunnel'); exit();
                    }

                    if ($clash !== '') {
                        ovp_log('WARN', "Второй впн выдал адрес $clash из подсети клиентов " . $net['cidr']);
                    } else {
                        ovp_log('ERR', 'Новый впн конфиг не поднялся');
                    }
                    ovp_bring_down();

                    if ($hadPrevious && file_exists(OVP_UP_BAK)) {
                        @copy(OVP_UP_BAK, OVP_UP_CONF);
                        @chmod(OVP_UP_CONF, 0640);
                        // Вместе с конфигом возвращаем и его учётные данные.
                        @unlink(OVP_UP_AUTH);
                        if (file_exists($authBak)) {
                            @copy($authBak, OVP_UP_AUTH);
                            @chmod(OVP_UP_AUTH, 0640);
                        }
                        $back = ovp_start_tunnel();
                        // Конфиг на месте — значит демон должен продолжать
                        // попытки, даже если откат с первого раза не сработал.
                        ovp_state_set('running');
                        ovp_log($back ? 'OK' : 'ERR', $back
                            ? 'Вернули предыдущий рабочий конфиг'
                            : 'Откат не помог, туннель не поднимается');
                        $reason = $clash !== ''
                            ? 'Новый конфиг выдал адрес ' . $clash . ' из подсети ваших клиентов '
                              . $net['cidr'] . ' — так работать нельзя'
                            : 'Новый конфиг не поднялся';
                        $notice = $back
                            ? $reason . ', вернули предыдущий'
                            : $reason . ', и откат не помог. Проверьте сеть сервера';
                    } else {
                        @unlink(OVP_UP_CONF);
                        @unlink(OVP_UP_AUTH);
                        ovp_state_set('stopped');
                        $notice = $clash !== ''
                            ? 'Второй впн выдал адрес ' . $clash . ' из подсети ваших клиентов '
                              . $net['cidr'] . '. Так работать нельзя: маршруты столкнутся и клиенты '
                              . 'станут недоступны. Попросите у продавца конфиг с другой адресацией.'
                            : 'Туннель не поднялся. Проверьте адрес сервера, логин с паролем и доступность '
                              . 'второго впн. Подробности — на вкладке «События».';
                    }
                    $noticeKind = 'err';
                }
            }
        }

        $hasConfig = file_exists(OVP_UP_CONF) && filesize(OVP_UP_CONF) > 0;
        $up        = ovp_iface_exists();
        $remote    = ovp_upstream_remote();
        $host      = $remote['host'];
        $port      = $remote['port'];
    }
}

// ── Состояние для отрисовки ────────────────────────────────────
$killswitch = ovp_killswitch_on();

/*
 * Состояние берём у демона, а не по одному наличию интерфейса.
 *
 * Интерфейс существует и в режиме обхода — поэтому без этих данных панель
 * писала бы «Подключено» ровно тогда, когда трафик идёт напрямую с адреса
 * сервера. Единственный индикатор для владельца врал бы в момент аварии.
 */
$health = ovp_health();

if (!$hasConfig) {
    $stateKind = 'off';
    $stateText = 'Не настроено';
    $stateNote = 'Сейчас сервер работает как обычный OpenVPN. Клиенты выходят в интернет с адреса самого сервера. Загрузите впн конфиг, чтобы включить двойной впн.';
} elseif ($up && $health['known'] && $health['bypass']) {
    $stateKind = 'warn';
    $stateText = 'Обход';
    $stateNote = 'Второй впн не отвечает, поэтому клиенты временно выходят напрямую через этот сервер. '
               . 'Сайты сейчас видят адрес этого сервера. Мониторинг продолжает попытки восстановить туннель — '
               . 'когда второй впн вернётся, трафик пойдёт через него автоматически.';
} elseif ($up && $health['known'] && !$health['alive']) {
    $stateKind = 'err';
    $stateText = 'Проверяется';
    $stateNote = 'Интерфейс поднят, но связь через туннель пока не подтверждена. Мониторинг разбирается.';
} elseif ($up) {
    $stateKind = 'ok';
    $stateText = 'Подключено';
    $stateNote = $health['known']
        ? 'Трафик клиентов идёт через второй впн.'
        : 'Интерфейс поднят. Демон мониторинга не отвечает, поэтому подтвердить прохождение трафика нельзя — '
          . 'проверьте: systemctl status ovpn-healthcheck';
} else {
    $stateKind = 'err';
    $stateText = 'Нет связи';
    $stateNote = $killswitch
        ? 'Впн конфиг загружен, но соединение не установлено. Интернета у клиентов сейчас нет — так работает Kill Switch.'
        : 'Впн конфиг загружен, но соединение не установлено. Клиенты сейчас работают напрямую через этот сервер.';
}
?>

<?php if ($notice !== ''): ?>
  <div class="notice notice--<?= $noticeKind ?>"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<div class="page-head">
  <div class="page-head__title">
    <h1>Подключение</h1>
    <span class="badge badge--<?= $stateKind ?>"><?= htmlspecialchars($stateText) ?></span>
  </div>
  <p class="page-head__note"><?= htmlspecialchars($stateNote) ?></p>
</div>

<div class="grid-2">

  <!-- ══ Состояние ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Состояние</h2></div>

    <div class="hero">
      <div class="hero__k">Адрес второго VPN</div>
      <div class="hero__v"><?= $host !== '' ? htmlspecialchars($host) : '—' ?></div>
      <div class="hero__meta">
        <?php if ($host === ''): ?>
          <span class="dot dot--off"></span> конфиг не загружен
        <?php else: ?>
          <span class="dot dot--<?= $up ? 'ok' : 'err' ?>" id="ping-dot"></span>
          <span id="ping-text">проверяем отклик…</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="facts facts--gap">
      <div class="fact">
        <span class="fact__k">Порт</span>
        <span class="fact__v"><?= $port !== '' ? htmlspecialchars($port) : '—' ?></span>
      </div>
      <div class="fact">
        <span class="fact__k">Соединение</span>
        <span class="fact__v"><?= $up ? 'активно' : 'нет' ?></span>
      </div>
      <div class="fact">
        <span class="fact__k">Подсеть клиентов</span>
        <span class="fact__v"><?= htmlspecialchars($net['cidr']) ?></span>
      </div>
    </div>

    <form method="post" class="form-col">
      <?= ovp_csrf_field() ?>
      <input type="hidden" name="menu" value="tunnel">

      <?php if (!$hasConfig): ?>
        <button class="btn btn--block" disabled>Загрузите второй VPN конфиг</button>
      <?php else: ?>
        <?php if (!$up): ?>
          <button type="submit" name="start" class="btn btn--ok btn--block">Подключить</button>
        <?php else: ?>
          <button type="submit" name="restart" class="btn btn--block">Переподключить</button>
        <?php endif; ?>
        <button type="submit" name="remove" class="btn btn--danger btn--block"
                onclick="return confirm('Отключить туннель и удалить впн конфиг?')">
          Отключить и удалить конфиг
        </button>
      <?php endif; ?>
    </form>
  </div>

  <!-- ══ Загрузка ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Второй VPN</h2></div>

    <form method="post" enctype="multipart/form-data" class="form-col" id="upload-form">
      <?= ovp_csrf_field() ?>
      <input type="hidden" name="menu" value="tunnel">

      <label class="drop" id="drop" for="config">
        <span class="drop__main" id="drop-main">Перетащите файл или нажмите</span>
        <span class="drop__sub">файл .ovpn от второго VPN</span>
        <input type="file" id="config" name="config" accept=".ovpn,.conf" hidden>
      </label>

      <div class="grid-2 grid-2--tight">
        <input class="input" type="text" name="vpn_user" autocomplete="off"
               placeholder="Логин (если требуется)">
        <input class="input" type="password" name="vpn_pass" autocomplete="new-password"
               placeholder="Пароль (если требуется)">
      </div>

      <button type="submit" class="btn btn--primary btn--block">Установить и подключить</button>
    </form>

    <p class="card__hint">
      Директивы, выполняющие команды на сервере, вырезаются из файла автоматически.
      Маршрутизация подставится сама под подсеть
      <span class="data"><?= htmlspecialchars($net['cidr']) ?></span>.
      Если новый конфиг не поднимется, вернётся предыдущий.
    </p>
  </div>
</div>

<!-- ══ Поведение при аварии ══ -->
<div class="card card--spaced">
  <div class="card__head">
    <h2 class="card__title">Если второй впн упадёт</h2>
    <span class="badge badge--<?= $killswitch ? 'warn' : 'off' ?>">
      <?= $killswitch ? 'Интернет отключится' : 'Работает напрямую' ?>
    </span>
  </div>

  <p class="card__lead">
    Второй впн иногда падает — у продавца закончилась подписка, сервер на ремонте,
    оборвалась связь. Здесь выбираете, что делать в такой момент.
  </p>

  <form method="post">
    <?= ovp_csrf_field() ?>
    <input type="hidden" name="menu" value="tunnel">

    <div class="items">
      <label class="item item--choice">
        <input type="radio" name="killswitch" value="off" <?= $killswitch ? '' : 'checked' ?>>
        <span>
          <span class="choice__title">Продолжать работу напрямую</span>
          <span class="choice__note">
            Интернет у клиентов не пропадёт — трафик пойдёт через этот сервер,
            как будто второго впн нет. Сайты увидят страну этого сервера,
            а не ту, что вы подключали. Когда второй впн оживёт, всё вернётся само.
          </span>
        </span>
      </label>

      <label class="item item--choice">
        <input type="radio" name="killswitch" value="on" <?= $killswitch ? 'checked' : '' ?> class="radio--warn">
        <span>
          <span class="choice__title">Отключать интернет — Kill Switch</span>
          <span class="choice__note">
            Интернет у клиентов пропадёт полностью, пока второй впн не вернётся.
            Зато ни один запрос точно не уйдёт с адреса этого сервера.
            Нужно, когда важно, чтобы сайты видели только страну второго впн.
          </span>
        </span>
      </label>
    </div>

    <button type="submit" class="btn btn--block btn--spaced">Сохранить</button>
  </form>

  <p class="card__hint">
    Настройка применяется в течение нескольких секунд, перезапускать ничего не нужно.
  </p>
</div>

<script>
  ovpInitDrop('drop', 'config', 'drop-main');
  ovpInitRemotePing(<?= json_encode($host, JSON_UNESCAPED_UNICODE) ?>);
</script>
