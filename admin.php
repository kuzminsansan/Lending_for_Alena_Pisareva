<?php
declare(strict_types=1);

session_name('ALENA_ADMIN_SESSION');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = file_exists($localConfigPath) ? require $localConfigPath : [];
$adminPassword = trim((string)($localConfig['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: 'change-this-password'));
$adminAuthSecret = hash('sha256', $adminPassword . '|' . (string)($localConfig['VK_BOT_TOKEN'] ?? getenv('VK_BOT_TOKEN') ?: ''));
$dataDir = __DIR__ . '/data';
$leadsPath = $dataDir . '/leads.json';
$adminAuthCookie = 'ALENA_ADMIN_AUTH';

$statusLabels = [
    'new' => 'Новая',
    'contacted' => 'Связались',
    'scheduled' => 'Записана',
    'completed' => 'Завершена',
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function readLeads(string $leadsPath): array
{
    if (!file_exists($leadsPath)) {
        return [];
    }

    $leads = json_decode((string)file_get_contents($leadsPath), true);

    return is_array($leads) ? $leads : [];
}

function saveLeads(string $dataDir, string $leadsPath, array $leads): void
{
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    file_put_contents($leadsPath, json_encode($leads, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function formatDate(string $value): string
{
    $timestamp = strtotime($value);

    return $timestamp ? date('d.m.Y H:i', $timestamp) : $value;
}

function statusClass(string $status): string
{
    return 'status-badge status-' . preg_replace('/[^a-z-]/', '', $status);
}

function makeAdminAuthCookieValue(string $secret): string
{
    $expires = (string)(time() + 60 * 60 * 12);
    $signature = hash_hmac('sha256', $expires, $secret);

    return $expires . '.' . $signature;
}

function isAdminAuthTokenValid(string $token, string $secret): bool
{
    $parts = explode('.', $token, 2);

    if (count($parts) !== 2 || !ctype_digit($parts[0])) {
        return false;
    }

    [$expires, $signature] = $parts;

    if ((int)$expires < time()) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $expires, $secret), $signature);
}

function getAdminAuthToken(string $cookieName): string
{
    return (string)($_POST['admin_auth'] ?? $_COOKIE[$cookieName] ?? '');
}

function setAdminAuthCookie(string $cookieName, string $token): void
{
    setcookie($cookieName, $token, [
        'expires' => time() + 60 * 60 * 12,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearAdminAuthCookie(string $cookieName): void
{
    setcookie($cookieName, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$currentAdminAuthToken = getAdminAuthToken($adminAuthCookie);
$isAuthorized = (
    ($_SESSION['admin_authorized'] ?? false) === true
    || isAdminAuthTokenValid($currentAdminAuthToken, $adminAuthSecret)
);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'login') {
        $password = trim((string)($_POST['password'] ?? ''));

        if (hash_equals($adminPassword, $password)) {
            session_regenerate_id(true);
            $_SESSION['admin_authorized'] = true;
            $currentAdminAuthToken = makeAdminAuthCookieValue($adminAuthSecret);
            setAdminAuthCookie($adminAuthCookie, $currentAdminAuthToken);
            $isAuthorized = true;
        }
        else {
            $error = 'Неверный пароль.';
        }
    }

    if ($action === 'logout') {
        $_SESSION = [];
        clearAdminAuthCookie($adminAuthCookie);
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
        header('Location: admin.php');
        exit;
    }

    if ($isAuthorized && in_array($action, ['update', 'delete'], true)) {
        $leads = readLeads($leadsPath);
        $id = (string)($_POST['id'] ?? '');

        if ($action === 'update') {
            $status = (string)($_POST['status'] ?? 'new');
            $note = trim((string)($_POST['note'] ?? ''));
            $status = array_key_exists($status, $statusLabels) ? $status : 'new';

            foreach ($leads as &$lead) {
                if (($lead['id'] ?? '') === $id) {
                    $lead['status'] = $status;
                    $lead['note'] = $note;
                    break;
                }
            }
            unset($lead);
        }

        if ($action === 'delete') {
            $leads = array_values(array_filter($leads, static fn (array $lead): bool => ($lead['id'] ?? '') !== $id));
        }

        saveLeads($dataDir, $leadsPath, $leads);
    }
}

$leads = $isAuthorized ? readLeads($leadsPath) : [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Админка заявок | Алёна Писарева</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Raleway:wght@700&display=swap"
    rel="stylesheet"
  />
  <link rel="stylesheet" href="styles.css" />
</head>
<body class="admin-page">
  <header class="admin-header">
    <div>
      <p class="eyebrow">Кабинет администратора</p>
      <h1>Заявки на консультацию</h1>
      <p>
        Здесь хранятся реальные заявки с сайта. Можно менять стадию обработки,
        вести заметку и удалять закрытые обращения.
      </p>
    </div>
    <div class="admin-header-actions">
      <?php if ($isAuthorized): ?>
        <span class="admin-counter"><?= count($leads) ?> заявок</span>
        <form method="post">
          <input type="hidden" name="action" value="logout" />
          <input type="hidden" name="admin_auth" value="<?= h($currentAdminAuthToken) ?>" />
          <button class="button button-secondary" type="submit">Выйти</button>
        </form>
      <?php endif; ?>
      <a class="button button-secondary" href="index.html">На сайт</a>
    </div>
  </header>

  <main class="admin-main">
    <?php if (!$isAuthorized): ?>
      <section class="admin-login">
        <h2>Вход в админку</h2>
        <p>Введите пароль администратора.</p>
        <?php if ($error !== ''): ?>
          <p class="admin-error" role="alert"><?= h($error) ?></p>
        <?php endif; ?>
        <form class="admin-login-form" method="post">
          <input type="hidden" name="action" value="login" />
          <label class="admin-field">
            Пароль
            <span class="password-field">
              <input id="admin-password" type="password" name="password" autocomplete="current-password" required />
              <button class="password-toggle" type="button" data-password-toggle aria-controls="admin-password">Показать</button>
            </span>
          </label>
          <button class="button button-primary" type="submit">Войти</button>
        </form>
      </section>
    <?php else: ?>
      <section class="admin-toolbar">
        <div>
          <h2>Воронка обработки</h2>
          <p>Новая -> Связались -> Записана -> Завершена</p>
        </div>
      </section>

      <?php if (!$leads): ?>
        <p class="admin-empty">
          Пока заявок нет. Новые обращения появятся здесь после отправки формы на сайте.
        </p>
      <?php endif; ?>

      <section class="admin-leads" aria-label="Список заявок">
        <?php foreach ($leads as $lead): ?>
          <?php
            $status = (string)($lead['status'] ?? 'new');
            $status = array_key_exists($status, $statusLabels) ? $status : 'new';
            $id = (string)($lead['id'] ?? '');
          ?>
          <article class="admin-lead-card">
            <div class="admin-lead-top">
              <div>
                <p class="admin-lead-date"><?= h(formatDate((string)($lead['createdAt'] ?? ''))) ?></p>
                <h2><?= h((string)($lead['name'] ?? 'Без имени')) ?></h2>
              </div>
              <span class="<?= h(statusClass($status)) ?>"><?= h($statusLabels[$status]) ?></span>
            </div>
            <div class="admin-lead-contact">
              <a href="tel:<?= h((string)($lead['phone'] ?? '')) ?>"><?= h((string)($lead['phone'] ?? '')) ?></a>
            </div>
            <p class="admin-lead-message"><?= h((string)($lead['message'] ?? 'Описание не указано.')) ?></p>
            <form class="admin-lead-form" method="post">
              <input type="hidden" name="action" value="update" />
              <input type="hidden" name="id" value="<?= h($id) ?>" />
              <input type="hidden" name="admin_auth" value="<?= h($currentAdminAuthToken) ?>" />
              <label class="admin-field">
                Стадия обработки
                <select name="status">
                  <?php foreach ($statusLabels as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="admin-field">
                Заметка администратора
                <textarea name="note" rows="3" placeholder="Например: договорились на вторник"><?= h((string)($lead['note'] ?? '')) ?></textarea>
              </label>
              <button class="button button-primary" type="submit">Сохранить</button>
            </form>
            <form method="post">
              <input type="hidden" name="action" value="delete" />
              <input type="hidden" name="id" value="<?= h($id) ?>" />
              <input type="hidden" name="admin_auth" value="<?= h($currentAdminAuthToken) ?>" />
              <button class="admin-delete" type="submit">Удалить заявку</button>
            </form>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </main>
  <script>
    const passwordToggle = document.querySelector("[data-password-toggle]");
    const passwordInput = document.querySelector("#admin-password");

    if (passwordToggle && passwordInput) {
      passwordToggle.addEventListener("click", () => {
        const isPasswordHidden = passwordInput.type === "password";
        passwordInput.type = isPasswordHidden ? "text" : "password";
        passwordToggle.textContent = isPasswordHidden ? "Скрыть" : "Показать";
      });
    }
  </script>
</body>
</html>
