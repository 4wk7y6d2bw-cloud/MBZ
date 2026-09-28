-- One-time test: set cash to 500 $ only for the account named admin
-- and only if it has administrator permissions.
UPDATE player_stats AS ps
INNER JOIN users AS u ON u.id = ps.user_id
SET ps.cash = 500
WHERE u.login = 'admin' AND u.is_admin = 1;

-- Confirm the result (zero rows means no admin account with this login).
SELECT u.id, u.login, ps.cash
FROM users AS u
INNER JOIN player_stats AS ps ON ps.user_id = u.id
WHERE u.login = 'admin' AND u.is_admin = 1;
