<?php
declare(strict_types=1);

/**
 * เชื่อมต่อ MSSQL ผ่าน PDO sqlsrv และฟังก์ชันช่วย query
 * ทุก query ใช้ prepared statement
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'sqlsrv:Server=' . config('db.server') . ';Database=' . config('db.database');
            if (config('db.trust_server_certificate')) {
                $dsn .= ';TrustServerCertificate=1';
            }
            // user ว่าง = Windows Authentication (ต้องส่ง null ไม่ใช่ string ว่าง)
            $user = (string) config('db.user', '');
            self::$pdo = new PDO(
                $dsn,
                $user !== '' ? $user : null,
                $user !== '' ? (string) config('db.pass') : null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
                    PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true,
                ],
            );
        }
        return self::$pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $v === false ? null : $v;
    }

    /** คืนจำนวนแถวที่ได้รับผล */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * INSERT แล้วคืน id ที่สร้าง — SQL ต้องมี OUTPUT INSERTED.id
     * เช่น INSERT INTO t (a) OUTPUT INSERTED.id VALUES (?)
     */
    public static function insert(string $sql, array $params = []): int
    {
        return (int) self::value($sql, $params);
    }

    /** รันใน transaction — ถ้า $fn throw จะ rollback */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }
}
