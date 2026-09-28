<?php

function user_logged_in(): bool
{
    return !empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

function current_user(): ?array
{
    return user_logged_in() ? $_SESSION['user'] : null;
}

function current_user_is_admin(): bool
{
    return user_logged_in() && (int) ($_SESSION['user']['is_admin'] ?? 0) === 1;
}

function ensure_player_stats(PDO $db, int $userId): void
{
    $stmt = $db->prepare(
        'INSERT IGNORE INTO player_stats
        (user_id, cash, credits, respect, profession, current_city, energy, tickets, strength, endurance, intelligence, charisma, cunning, tickets_last_grant)
        VALUES (:user_id, 500, 0, 52, NULL, NULL, 100, 25, 10, 10, 10, 10, 10, NOW())'
    );
    $stmt->execute(['user_id' => $userId]);
}

function login_user(PDO $db, string $login, string $password): array
{
    $login = trim($login);
    if ($login === '' || $password === '') return ['success' => false, 'message' => 'Wpisz login i hasło.'];

    $stmt = $db->prepare('SELECT id, login, email, password_hash, is_admin, active FROM users WHERE login = :login LIMIT 1');
    $stmt->execute(['login' => $login]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['active'] !== 1 || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Nieprawidłowy login lub hasło.'];
    }

    ensure_player_stats($db, (int) $user['id']);
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'login' => (string) $user['login'],
        'email' => (string) $user['email'],
        'is_admin' => (int) $user['is_admin'],
    ];
    return ['success' => true, 'message' => ''];
}

function register_user(PDO $db, string $login, string $email, string $password, string $passwordRepeat): array
{
    $login = trim($login);
    $email = trim(mb_strtolower($email));
    if ($login === '' || $email === '' || $password === '' || $passwordRepeat === '') return ['success' => false, 'message' => 'Uzupełnij wszystkie pola.'];
    if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $login)) return ['success' => false, 'message' => 'Login musi mieć 3–24 znaki i może zawierać litery, cyfry oraz _.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'Podaj poprawny adres e-mail.'];
    if (strlen($password) < 8) return ['success' => false, 'message' => 'Hasło musi mieć minimum 8 znaków.'];
    if ($password !== $passwordRepeat) return ['success' => false, 'message' => 'Hasła nie są identyczne.'];

    $stmt = $db->prepare('SELECT id FROM users WHERE login = :login OR email = :email LIMIT 1');
    $stmt->execute(['login' => $login, 'email' => $email]);
    if ($stmt->fetch()) return ['success' => false, 'message' => 'Taki login lub adres e-mail jest już zajęty.'];

    try {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO users (login, email, password_hash, is_admin, active, created_at) VALUES (:login, :email, :password_hash, 0, 1, NOW())');
        $stmt->execute(['login' => $login, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        ensure_player_stats($db, (int) $db->lastInsertId());
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    return login_user($db, $login, $password);
}

function regenerate_player_energy(PDO $db, int $userId): void
{
    // The column is created once by the deploy migration; this fallback also supports existing installs.
    static $columnChecked = false;
    if (!$columnChecked) {
        $column = $db->query("SHOW COLUMNS FROM player_stats LIKE 'energy_updated_at'")->fetch();
        if (!$column) {
            $db->exec("ALTER TABLE player_stats ADD COLUMN energy_updated_at DATETIME NULL DEFAULT NULL");
        }
        $columnChecked = true;
    }

    // Atomic update: elapsed full 90-second intervals are consumed exactly once, even on simultaneous requests.
    $stmt = $db->prepare("UPDATE player_stats SET
        energy = LEAST(100, energy + CASE
            WHEN energy >= 100 OR energy_updated_at IS NULL THEN 0
            ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, energy_updated_at, NOW()) DIV 90)
        END),
        energy_updated_at = CASE
            WHEN energy >= 100 THEN NOW()
            WHEN energy_updated_at IS NULL THEN NOW()
            ELSE DATE_ADD(energy_updated_at, INTERVAL
                GREATEST(0, TIMESTAMPDIFF(SECOND, energy_updated_at, NOW()) DIV 90) * 90 SECOND)
        END
        WHERE user_id = ?");
    $stmt->execute([$userId]);
}

function get_player_stats(PDO $db, int $userId): ?array
{
    ensure_player_stats($db, $userId);
    regenerate_player_energy($db, $userId);
    $sync = $db->prepare('UPDATE player_stats SET respect=FLOOR(GREATEST(0,cash)/10)+FLOOR((GREATEST(0,strength)+GREATEST(0,endurance)+GREATEST(0,intelligence)+GREATEST(0,charisma)+GREATEST(0,cunning))/20) WHERE user_id=:user_id');
    $sync->execute(['user_id'=>$userId]);
    $stmt = $db->prepare('SELECT * FROM player_stats WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $stats = $stmt->fetch();
    return $stats ?: null;
}

function get_player_rank(PDO $db, int $userId): ?int
{
    $stmt = $db->prepare('SELECT public_respect, user_id FROM player_stats WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $player = $stmt->fetch();
    if (!$player) return null;

    $stmt = $db->prepare(
        'SELECT COUNT(*) + 1
         FROM player_stats
         WHERE public_respect > :respect_above
            OR (public_respect = :respect_equal AND user_id < :user_id)'
    );
    $stmt->execute([
        'respect_above' => (int) $player['public_respect'],
        'respect_equal' => (int) $player['public_respect'],
        'user_id' => $userId,
    ]);

    return (int) $stmt->fetchColumn();
}

function logout_user(): void
{
    unset($_SESSION['user']);
    session_regenerate_id(true);
}

function require_login(): void
{
    if (!user_logged_in()) redirect('./');
}

function require_admin(): void
{
    if (!user_logged_in()) redirect('./');
    if (!current_user_is_admin()) {
        http_response_code(403);
        exit('Brak uprawnień.');
    }
}


function ensure_respect_history_table(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS respect_history (
        user_id INT NOT NULL,
        season INT NOT NULL,
        game_day INT NOT NULL,
        respect BIGINT NOT NULL,
        recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, season, game_day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function get_respect_history(PDO $db, int $userId, int $season): array
{
    $stmt = $db->prepare('SELECT game_day, respect FROM respect_history
        WHERE user_id = :user_id AND season = :season ORDER BY game_day');
    $stmt->execute(['user_id' => $userId, 'season' => $season]);
    return $stmt->fetchAll();
}

function update_game_clock(PDO $db): array
{
    ensure_respect_history_table($db);
    $db->beginTransaction();
    // All cash and all character stats count toward respect, without a starting bonus.
    $db->exec('UPDATE player_stats SET respect=FLOOR(GREATEST(0,cash)/10)+FLOOR((GREATEST(0,strength)+GREATEST(0,endurance)+GREATEST(0,intelligence)+GREATEST(0,charisma)+GREATEST(0,cunning))/20) WHERE respect<>FLOOR(GREATEST(0,cash)/10)+FLOOR((GREATEST(0,strength)+GREATEST(0,endurance)+GREATEST(0,intelligence)+GREATEST(0,charisma)+GREATEST(0,cunning))/20)');
    try {
        $row = $db->query('SELECT * FROM game_state WHERE id = 1 FOR UPDATE')->fetch();
        if (!$row) throw new RuntimeException('Brak stanu gry.');

        $now = new DateTimeImmutable('now');

        while (true) {
            $next = new DateTimeImmutable($row['next_ranking_update']);

            if ((int) $row['is_break'] === 1) {
                if ($now < $next) break;

                $row['season'] = (int) $row['season'] + 1;
                $row['game_day'] = 1;
                $row['is_break'] = 0;
                $next = $next->modify('+4 hours');

                $stmt = $db->prepare('UPDATE game_state SET season=:season, game_day=1, is_break=0, next_ranking_update=:next_update WHERE id=1');
                $stmt->execute([
                    'season' => $row['season'],
                    'next_update' => $next->format('Y-m-d H:i:s'),
                ]);
                $row['next_ranking_update'] = $next->format('Y-m-d H:i:s');
                continue;
            }

            if ($now < $next) break;

            // Save the closing respect for the day that just ended.
            $snapshot = $db->prepare('INSERT INTO respect_history (user_id, season, game_day, respect)
                SELECT user_id, :season, :game_day, respect FROM player_stats
                ON DUPLICATE KEY UPDATE respect = VALUES(respect), recorded_at = NOW()');
            $snapshot->execute(['season' => (int) $row['season'], 'game_day' => (int) $row['game_day']]);
            $db->exec('UPDATE player_stats SET public_respect = respect');

            if ((int) $row['game_day'] >= 60) {
                $row['is_break'] = 1;
                $next = $next->modify('+24 hours');
            } else {
                $row['game_day'] = (int) $row['game_day'] + 1;
                $next = $next->modify('+4 hours');
            }

            $stmt = $db->prepare('UPDATE game_state SET game_day=:game_day, season=:season, is_break=:is_break, next_ranking_update=:next_update WHERE id=1');
            $stmt->execute([
                'game_day' => $row['game_day'],
                'season' => $row['season'],
                'is_break' => $row['is_break'],
                'next_update' => $next->format('Y-m-d H:i:s'),
            ]);
            $row['next_ranking_update'] = $next->format('Y-m-d H:i:s');
        }

        $db->commit();
        return $row;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
