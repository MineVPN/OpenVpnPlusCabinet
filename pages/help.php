<?php
/**
 * OpenVPN+ — страница «Инструкция».
 *
 * Написана для человека, который не администрирует серверы. Никаких
 * терминов без объяснения, каждый шаг — что нажать и что должно
 * получиться. Подсеть и состояние подставляются реальные, чтобы человек
 * видел свои значения, а не абстрактный пример.
 */

require_once __DIR__ . '/../includes/ovp_helpers.php';
ovp_require_auth();

$net       = ovp_server_net();
$hasConfig = file_exists(OVP_UP_CONF) && filesize(OVP_UP_CONF) > 0;
$up        = ovp_iface_exists();
$health    = ovp_health();
?>

<div class="page-head">
  <div class="page-head__title"><h1>Инструкция</h1></div>
  <p class="page-head__note">
    Как это работает и что делать по шагам. Если что-то не получается — читайте раздел
    «Если не работает» внизу страницы.
  </p>
</div>

<div class="stack">

  <!-- ══ Что это ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Что такое OpenVPN+</h2></div>

    <p class="card__lead">
      Это услуга для подключения к любой стране без ограничений.
      Напрямую так подключиться нельзя. Нужен <strong>промежуточный сервер в Европе</strong>,
      и OpenVPN+ как раз им и является.
    </p>

    <div class="flow">
      <div class="flow__node">
        <div class="flow__icon">📱</div>
        <div class="flow__name">Ваше устройство</div>
        <div class="flow__sub">телефон, ноутбук, роутер</div>
      </div>
      <div class="flow__arrow">→</div>
      <div class="flow__node flow__node--accent">
        <div class="flow__icon">🇪🇺</div>
        <div class="flow__name">Этот сервер</div>
        <div class="flow__sub">промежуточный,<br>в Европе</div>
      </div>
      <div class="flow__arrow">→</div>
      <div class="flow__node">
        <div class="flow__icon">🌍</div>
        <div class="flow__name">Второй впн</div>
        <div class="flow__sub">нужная вам страна</div>
      </div>
      <div class="flow__arrow">→</div>
      <div class="flow__node">
        <div class="flow__icon">🌐</div>
        <div class="flow__name">Интернет</div>
        <div class="flow__sub">сайты и сервисы</div>
      </div>
    </div>

    <div class="callout callout--warn callout--spaced">
      <span class="callout__mark">❗</span>
      <span>
        <strong>Второй впн в комплект не входит</strong> — его нужно купить отдельно.
        Вы покупаете этот промежуточный сервер и панель управления, а конфиг нужной
        страны берётся у любого продавца. Подойдёт обычный OpenVPN, не плюс.
      </span>
    </div>
  </div>

  <!-- ══ Что уже есть ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Что у вас уже есть</h2></div>

    <div class="bullets">
      <div class="bullet">
        <span class="bullet__mark">🔑</span>
        <span><strong>Конфиг для ваших устройств</strong> — файл <code>.ovpn</code>,
        который вам выдал продавец. Он <strong>один на все устройства</strong>:
        копируйте его хоть на десять телефонов, каждому сервер выдаст
        свой внутренний адрес.</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">🖥️</span>
        <span><strong>Эта панель управления</strong> — вы сейчас в ней.
        Здесь ставится второй впн и настраиваются исключения.</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">🔢</span>
        <span><strong>Подсеть <?= htmlspecialchars($net['cidr']) ?></strong> —
        внутренние адреса ваших устройств.</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">🛡️</span>
        <span><strong>Автоматический мониторинг</strong> — сервер сам следит за связью
        и переподключается при обрыве. Заходить в панель для этого не нужно.</span>
      </div>
    </div>
  </div>

  <!-- ══ Шаги ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Что делать по шагам</h2></div>

    <div class="steps">

      <div class="step">
        <div class="step__num">1</div>
        <div>
          <div class="step__title">Подключите свои устройства</div>
          <div class="step__body">
            <p>Установите приложение <strong>OpenVPN Connect</strong> — оно бесплатное
            и есть в Google Play, App Store и на сайте openvpn.net для компьютера.</p>
            <p>Откройте приложение, выберите <strong>«Импорт профиля» → «Файл»</strong>
            и добавьте выданный вам файл <code>.ovpn</code>.</p>
            <p>Включите переключатель. Всё — устройство подключено к этому серверу.</p>
            <p>На остальных устройствах повторите с <strong>тем же самым файлом</strong> —
            заводить отдельный на каждое не нужно.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">2</div>
        <div>
          <div class="step__title">Проверьте, что подключение работает</div>
          <div class="step__body">
            <p>Откройте в браузере <strong>2ip.ru</strong> или <strong>whatismyip.com</strong>.
            Сайт покажет ваш адрес и страну.</p>
            <p>Должна быть страна <strong>этого сервера</strong> (обычно европейская),
            а не ваша. Если так — первая половина цепочки работает.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">3</div>
        <div>
          <div class="step__title">Купите второй впн нужной страны</div>
          <div class="step__body">
            <p>Нужен конфиг <strong>именно для OpenVPN</strong> — файл с расширением
            <code>.ovpn</code>. Конфиги WireGuard (<code>.conf</code> с секциями
            <code>[Interface]</code> и <code>[Peer]</code>) сюда не подойдут.</p>
            <p>Если продавец выдаёт ещё и логин с паролем — это нормально,
            в панели есть поля для них.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">4</div>
        <div>
          <div class="step__title">Загрузите его в панель</div>
          <div class="step__body">
            <p>Откройте раздел <strong>Подключение</strong> в меню слева.</p>
            <p>Справа есть поле <strong>«Перетащите файл или нажмите»</strong> — перетащите
            туда файл <code>.ovpn</code> или кликните и выберите его на компьютере.</p>
            <p>Если конфиг требует логин и пароль, заполните два поля под окном загрузки.
            Без них туннель не поднимется, и панель об этом скажет.</p>
            <p>Нажмите <strong>«Установить и подключить»</strong> и подождите
            секунд двадцать: OpenVPN устанавливает связь дольше, чем WireGuard.</p>
            <p>Если наверху появилась зелёная надпись <strong>«Подключено»</strong> — готово.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">5</div>
        <div>
          <div class="step__title">Проверьте результат</div>
          <div class="step__body">
            <p>Снова откройте <strong>2ip.ru</strong> с подключённого устройства.</p>
            <p>Теперь должна показываться страна <strong>второго впн</strong>,
            а не европейская. Значит цепочка собралась полностью.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">6</div>
        <div>
          <div class="step__title">Решите, что делать при аварии</div>
          <div class="step__body">
            <p>Второй впн рано или поздно отвалится — это нормально.
            Внизу страницы <strong>Подключение</strong> есть блок
            <strong>«Если второй впн упадёт»</strong> с двумя вариантами.</p>
            <p><strong>Продолжать работу напрямую</strong> — так стоит сразу.
            Интернет не пропадёт, но на время аварии сайты увидят страну этого сервера.
            Подходит, когда главное — чтобы работа не останавливалась.</p>
            <p><strong>Kill Switch</strong> — интернет отключится полностью, пока второй
            впн не вернётся. Нужен, когда важно, чтобы сайты видели только страну
            второго впн и никогда — европейскую.</p>
            <p>Поменять можно в любой момент, настройка применяется за несколько секунд.</p>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ══ Обход ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Раздел «Обход VPN» — зачем он</h2></div>

    <p class="card__lead">
      Трафик через два впн идёт дольше, чем через один. Для сайтов это незаметно,
      а вот для звонков и телефонии задержка мешает.
    </p>

    <p class="card__lead">
      В разделе <strong>Обход VPN</strong> можно указать адреса, которые пойдут
      <strong>напрямую через этот сервер</strong>, минуя второй впн. Получается короче
      и быстрее.
    </p>

    <div class="bullets">
      <div class="bullet">
        <span class="bullet__mark">📞</span>
        <span>Серверы телефонии и SIP — чтобы не было эха и задержки в разговоре</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">💼</span>
        <span>CRM и рабочие сервисы, где важна скорость отклика</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">🎮</span>
        <span>Игровые серверы, если важен пинг</span>
      </div>
    </div>

    <div class="callout callout--info callout--spaced">
      <span class="callout__mark">💡</span>
      <span>
        Добавлять нужно <strong>IP-адрес</strong>, а не название сайта. Узнать адрес можно
        командой <code>ping адрес-сервиса.ru</code> в командной строке — он покажется в скобках.
      </span>
    </div>

    <div class="callout callout--warn callout--tight">
      <span class="callout__mark">⛔</span>
      <span>
        Адреса <span class="data">8.8.8.8</span>, <span class="data">8.8.4.4</span>,
        <span class="data">1.1.1.1</span>, <span class="data">1.0.0.1</span> и
        <span class="data">9.9.9.9</span> добавить нельзя — ими сервер проверяет,
        жив ли второй впн. Если пустить их мимо туннеля, проверка всегда будет успешной,
        и падения перестанут замечаться. Для DNS укажите другой сервер:
        <span class="data">77.88.8.8</span> (Яндекс) или
        <span class="data">208.67.222.222</span> (OpenDNS).
      </span>
    </div>
  </div>

  <!-- ══ Остальные разделы ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Остальные разделы панели</h2></div>

    <div class="facts">
      <div class="fact fact--prose">
        <span class="fact__k">Подключение</span>
        <span class="fact__v">Главная страница. Здесь ставится второй впн, видно его адрес и состояние связи</span>
      </div>
      <div class="fact fact--prose">
        <span class="fact__k">Обход VPN</span>
        <span class="fact__v">Адреса, которые идут напрямую, минуя второй впн</span>
      </div>
      <div class="fact fact--prose">
        <span class="fact__k">Пинг</span>
        <span class="fact__v">Проверка связи. По умолчанию идёт тем же путём, что и трафик клиента</span>
      </div>
      <div class="fact fact--prose">
        <span class="fact__k">События</span>
        <span class="fact__v">Что происходило с сервером: когда связь пропадала и когда восстановилась</span>
      </div>
    </div>
  </div>

  <!-- ══ Если не работает ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Если не работает</h2></div>

    <div class="qa">

      <details class="qa__item">
        <summary class="qa__q">Загрузил конфиг, но пишет «Нет связи»</summary>
        <div class="qa__a">
          <p>Сначала откройте раздел <strong>События</strong> — там будет причина
          прямым текстом, гадать не придётся.</p>
          <p>Самое частое: <strong>неверный логин или пароль</strong> ко второму впн.
          В журнале это видно как ошибка проверки подлинности. Загрузите конфиг заново,
          аккуратно заполнив оба поля.</p>
          <p>Второе по частоте — у продавца второго впн закончилась подписка или сервер
          лежит. Проверьте тот же конфиг на телефоне напрямую: если и там не работает,
          дело в нём.</p>
          <p>Третье — проверьте, что файл <strong>для OpenVPN</strong>, а не для WireGuard.
          Откройте блокнотом: внутри должна быть строка <code>remote</code>, а не
          <code>[Interface]</code>.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Пишет «адрес пересекается с подсетью ваших клиентов»</summary>
        <div class="qa__a">
          <p>Второй впн выдал адрес из той же сети, что у ваших устройств
          (<span class="data"><?= htmlspecialchars($net['cidr']) ?></span>). Сервер
          не сможет понять, куда отправлять ответы.</p>
          <p>Панель отклоняет такой конфиг сразу, и это к лучшему: иначе всё бы заработало,
          а сломалось позже — после перезагрузки сервера, без видимой причины.</p>
          <p>Решение: попросите у продавца второго впн конфиг с другой внутренней
          адресацией. Обычно выдают сразу, это частая просьба.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Пишет «конфиг требует логин и пароль»</summary>
        <div class="qa__a">
          <p>Так и есть: внутри файла указано, что нужны учётные данные, а поля под окном
          загрузки остались пустыми. Заполните их и загрузите конфиг ещё раз.</p>
          <p>Если продавец логин с паролем не давал — запросите. Без них такой конфиг
          не поднимется нигде, не только здесь.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Какая страна будет, если второй впн упадёт</summary>
        <div class="qa__a">
          <p>По умолчанию интернет продолжит работать — трафик пойдёт через этот сервер.
          Сайты в этот момент увидят его страну, а не ту, что вы подключали. Когда второй
          впн оживёт, всё вернётся само.</p>
          <p>Если так нельзя — на странице <strong>Подключение</strong> внизу выберите
          <strong>Kill Switch</strong>. Тогда интернет будет отключаться полностью,
          и ни один запрос точно не уйдёт с адреса этого сервера.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">У клиентов пропал интернет</summary>
        <div class="qa__a">
          <p>Значит включён <strong>Kill Switch</strong>, а второй впн сейчас недоступен.
          Так и задумано: лучше без интернета, чем с чужого адреса.</p>
          <p>Быстрое решение: на странице <strong>Подключение</strong> поставьте
          <strong>«Продолжать работу напрямую»</strong> и нажмите Сохранить. Интернет
          появится через несколько секунд, но уже без второй страны.</p>
          <p>Если Kill Switch выключен, а интернета всё равно нет — проблема не в настройке.
          Посмотрите статус на странице Подключение и журнал в разделе События.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Показывает не ту страну</summary>
        <div class="qa__a">
          <p>Если показывает <strong>европейскую</strong> — значит второй впн не подключён.
          Посмотрите статус на странице «Подключение».</p>
          <p>Если показывает <strong>вашу собственную</strong> — устройство вообще
          не подключено к впн. Проверьте, включён ли переключатель в приложении.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Всё работает, но медленно</summary>
        <div class="qa__a">
          <p>Трафик проходит через два сервера подряд — это всегда медленнее прямого
          подключения.</p>
          <p>Если тормозит конкретный сервис — добавьте его адрес в <strong>Обход VPN</strong>,
          он пойдёт коротким путём. Если тормозит вообще всё — скорее всего медленный
          второй впн, попробуйте другого продавца.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Сайты открываются наполовину или зависают на загрузке</summary>
        <div class="qa__a">
          <p>Похоже на проблему с размером пакетов: в цепочке из двух туннелей полезный
          размер уменьшается дважды. Сервер настраивает это автоматически, но некоторые
          провайдеры второго впн ведут себя нестандартно.</p>
          <p>Проверьте на странице <strong>Пинг</strong> в режиме «через второй впн»:
          если потерь нет, а страницы всё равно рвутся — дело именно в этом,
          и стоит попробовать другой конфиг второго впн.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Связь пропадает и появляется сама</summary>
        <div class="qa__a">
          <p>Сервер следит за связью и восстанавливает её автоматически за несколько
          секунд — вмешиваться не нужно.</p>
          <p>Посмотрите раздел <strong>События</strong>: там видно, как часто это происходит.
          Если обрывы постоянные — проблема на стороне второго впн.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Забыл пароль от панели</summary>
        <div class="qa__a">
          <p>Пароль выдаётся при установке сервера и хранится у того, кто его ставил.
          Восстановить через панель нельзя — обратитесь к продавцу услуги.</p>
        </div>
      </details>

    </div>
  </div>

  <!-- ══ Текущее состояние ══ -->
  <?php if (!$hasConfig): ?>
    <div class="callout callout--info">
      <span class="callout__mark">👉</span>
      <span>
        Сейчас второй впн не загружен — сервер работает как обычный OpenVPN.
        Чтобы включить двойной впн, перейдите в раздел <strong>Подключение</strong>
        и загрузите конфиг.
      </span>
    </div>
  <?php elseif ($up && $health['known'] && $health['bypass']): ?>
    <div class="callout callout--warn">
      <span class="callout__mark">⚠️</span>
      <span>
        Сейчас второй впн не отвечает, и клиенты временно выходят напрямую через этот
        сервер. Мониторинг продолжает попытки — когда связь вернётся, трафик пойдёт
        через второй впн автоматически.
      </span>
    </div>
  <?php elseif ($up): ?>
    <div class="callout callout--ok">
      <span class="callout__mark">✅</span>
      <span>Сейчас всё настроено и работает: трафик идёт через второй впн.</span>
    </div>
  <?php else: ?>
    <div class="callout callout--warn">
      <span class="callout__mark">⚠️</span>
      <span>
        Конфиг второго впн загружен, но связи с ним нет. Посмотрите раздел
        <strong>«Если не работает»</strong> выше — первый вопрос про это.
      </span>
    </div>
  <?php endif; ?>

</div>
