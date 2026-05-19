<?php

namespace ZyCrypt\Laravel\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class DatabaseGuard
{
    private Connection $db;
    private string     $driver;

    public function __construct()
    {
        $this->db     = DB::connection();
        $this->driver = $this->db->getDriverName(); // mysql | pgsql | sqlite
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Install zycrypt_tokens table + triggers/RLS into the application database.
     * Idempotent — safe to run multiple times.
     */
    public function install(): void
    {
        match ($this->driver) {
            'pgsql'  => $this->installPostgres(),
            'mysql'  => $this->installMysql(),
            default  => null,   // sqlite / testing — skip silently
        };
    }

    /**
     * Remove all ZyCrypt database guards (triggers, RLS, table).
     */
    public function remove(): void
    {
        match ($this->driver) {
            'pgsql'  => $this->removePostgres(),
            'mysql'  => $this->removeMysql(),
            default  => null,
        };
    }

    /**
     * Emit a short-lived session token into the database.
     * Must be called on every HTTP request BEFORE any query runs.
     *
     * @param string $token  Opaque token issued by LicenseValidator::generateToken()
     */
    public function activateSession(string $token): void
    {
        match ($this->driver) {
            'pgsql'  => $this->activatePostgres($token),
            'mysql'  => $this->activateMysql($token),
            default  => null,
        };
    }

    /**
     * True when the guard table exists in the current DB.
     */
    public function isInstalled(): bool
    {
        try {
            return match ($this->driver) {
                'pgsql' => (bool) $this->db->selectOne(
                    "SELECT to_regclass('public.zycrypt_tokens') IS NOT NULL AS exists"
                )?->exists,
                'mysql' => (bool) $this->db->selectOne(
                    "SELECT COUNT(*) AS cnt FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = 'zycrypt_tokens'"
                )?->cnt,
                default => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * List all application tables (excluding zycrypt_ prefixed ones).
     * Used to know which tables to attach triggers to.
     */
    public function applicationTables(): array
    {
        return match ($this->driver) {
            'pgsql' => array_column(
                $this->db->select(
                    "SELECT tablename FROM pg_tables
                     WHERE schemaname = 'public'
                       AND tablename NOT LIKE 'zycrypt_%'
                       AND tablename NOT IN ('schema_migrations','migrations')"
                ),
                'tablename'
            ),
            'mysql' => array_column(
                $this->db->select(
                    "SELECT table_name AS tablename FROM information_schema.tables
                     WHERE table_schema = DATABASE()
                       AND table_name NOT LIKE 'zycrypt_%'
                       AND table_name NOT IN ('migrations')"
                ),
                'tablename'
            ),
            default => [],
        };
    }

    // ── PostgreSQL ─────────────────────────────────────────────────────────────

    private function installPostgres(): void
    {
        // 1. Token table
        $this->db->unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS zycrypt_tokens (
                id         BIGSERIAL PRIMARY KEY,
                token      TEXT        NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL DEFAULT (NOW() + INTERVAL '15 minutes'),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
            CREATE INDEX IF NOT EXISTS idx_zycrypt_tokens_token ON zycrypt_tokens (token);
            CREATE INDEX IF NOT EXISTS idx_zycrypt_tokens_exp   ON zycrypt_tokens (expires_at);
        SQL);

        // 2. Validation function — called by every trigger
        $this->db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION zycrypt_check_session()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            DECLARE
                v_token TEXT;
            BEGIN
                BEGIN
                    v_token := current_setting('app.zycrypt_token', true);
                EXCEPTION WHEN OTHERS THEN
                    v_token := NULL;
                END;

                IF v_token IS NULL OR v_token = '' THEN
                    RAISE EXCEPTION 'zycrypt: no active session token'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM zycrypt_tokens
                    WHERE  token = v_token
                    AND    expires_at > NOW()
                ) THEN
                    RAISE EXCEPTION 'zycrypt: session token invalid or expired'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        // 3. Cleanup function — removes stale tokens
        $this->db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION zycrypt_cleanup_tokens()
            RETURNS void LANGUAGE plpgsql AS $$
            BEGIN
                DELETE FROM zycrypt_tokens WHERE expires_at < NOW() - INTERVAL '1 hour';
            END;
            $$;
        SQL);

        // 4. Attach trigger to every application table
        foreach ($this->applicationTables() as $table) {
            $triggerName = 'zycrypt_guard_' . $table;
            $this->db->unprepared(<<<SQL
                DROP TRIGGER IF EXISTS {$triggerName} ON "{$table}";
                CREATE TRIGGER {$triggerName}
                    BEFORE INSERT OR UPDATE OR DELETE ON "{$table}"
                    FOR EACH ROW EXECUTE FUNCTION zycrypt_check_session();
            SQL);
        }
    }

    private function removePostgres(): void
    {
        // Remove triggers from all tables
        $triggers = $this->db->select(<<<'SQL'
            SELECT trigger_name, event_object_table
            FROM   information_schema.triggers
            WHERE  trigger_schema = 'public'
            AND    trigger_name LIKE 'zycrypt_guard_%'
        SQL);

        foreach ($triggers as $row) {
            $this->db->unprepared(
                "DROP TRIGGER IF EXISTS \"{$row->trigger_name}\" ON \"{$row->event_object_table}\""
            );
        }

        $this->db->unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS zycrypt_check_session() CASCADE;
            DROP FUNCTION IF EXISTS zycrypt_cleanup_tokens() CASCADE;
            DROP TABLE   IF EXISTS zycrypt_tokens CASCADE;
        SQL);
    }

    private function activatePostgres(string $token): void
    {
        // Persist token record (15-minute window covers normal request lifecycle)
        $this->db->statement(
            "INSERT INTO zycrypt_tokens (token, expires_at) VALUES (?, NOW() + INTERVAL '15 minutes')",
            [$token]
        );

        // Set session variable — triggers read this
        $this->db->statement("SELECT set_config('app.zycrypt_token', ?, false)", [$token]);

        // Opportunistic cleanup (1-in-50 chance per request to keep table lean)
        if (random_int(1, 50) === 1) {
            $this->db->statement('SELECT zycrypt_cleanup_tokens()');
        }
    }

    // ── MySQL ──────────────────────────────────────────────────────────────────

    private function installMysql(): void
    {
        // 1. Token table
        $this->db->unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS zycrypt_tokens (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token      VARCHAR(512) NOT NULL,
                expires_at DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token     (token(64)),
                INDEX idx_expires   (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL);

        // 2. Attach triggers to every application table
        //    MySQL has no stored function readable inside triggers for session vars,
        //    so we use @zycrypt_token user variable and check against the table directly.
        foreach ($this->applicationTables() as $table) {
            $ins = 'zycrypt_ins_' . $table;
            $upd = 'zycrypt_upd_' . $table;
            $del = 'zycrypt_del_' . $table;

            // Drop existing before re-creating
            $this->db->unprepared("DROP TRIGGER IF EXISTS `{$ins}`");
            $this->db->unprepared("DROP TRIGGER IF EXISTS `{$upd}`");
            $this->db->unprepared("DROP TRIGGER IF EXISTS `{$del}`");

            $body = $this->mysqlTriggerBody();

            $this->db->unprepared(<<<SQL
                CREATE TRIGGER `{$ins}` BEFORE INSERT ON `{$table}` FOR EACH ROW BEGIN {$body} END
            SQL);
            $this->db->unprepared(<<<SQL
                CREATE TRIGGER `{$upd}` BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN {$body} END
            SQL);
            $this->db->unprepared(<<<SQL
                CREATE TRIGGER `{$del}` BEFORE DELETE ON `{$table}` FOR EACH ROW BEGIN {$body} END
            SQL);
        }
    }

    private function mysqlTriggerBody(): string
    {
        return <<<'SQL'
            IF @zycrypt_token IS NULL OR @zycrypt_token = '' THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'zycrypt: no active session token';
            END IF;
            IF (SELECT COUNT(*) FROM zycrypt_tokens
                WHERE token = @zycrypt_token AND expires_at > NOW()) = 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'zycrypt: session token invalid or expired';
            END IF;
        SQL;
    }

    private function removeMysql(): void
    {
        foreach ($this->applicationTables() as $table) {
            $this->db->unprepared("DROP TRIGGER IF EXISTS `zycrypt_ins_{$table}`");
            $this->db->unprepared("DROP TRIGGER IF EXISTS `zycrypt_upd_{$table}`");
            $this->db->unprepared("DROP TRIGGER IF EXISTS `zycrypt_del_{$table}`");
        }

        $this->db->unprepared('DROP TABLE IF EXISTS zycrypt_tokens');
    }

    private function activateMysql(string $token): void
    {
        $this->db->statement(
            'INSERT INTO zycrypt_tokens (token, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))',
            [$token]
        );

        // Set user-defined variable — visible within this connection only
        $this->db->statement('SET @zycrypt_token = ?', [$token]);

        if (random_int(1, 50) === 1) {
            $this->db->statement(
                'DELETE FROM zycrypt_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
        }
    }
}
