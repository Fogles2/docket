<?php
class Database {
    private $db;
    private $path;

    public function __construct($file = null) {
        $this->path = $file ?: (__DIR__ . '/docket.db');
        $this->db = new SQLite3($this->path);
        // Enable WAL for concurrent safety
        $this->db->exec('PRAGMA journal_mode = WAL;');
    }

    public function init() {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_number TEXT,
    defendant TEXT,
    dob TEXT,
    sex TEXT,
    charge TEXT,
    crime_category TEXT,
    age_range TEXT,
    source_days TEXT,
    created_at DATETIME DEFAULT (datetime('now')),
    created_date TEXT DEFAULT (date('now'))
);
SQL;
        $this->db->exec($sql);

        // Unique index to reduce duplicates for same case_number & source_days on the same date
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_case_per_day_source ON cases(case_number, source_days, created_date);');
    }

    // Save enriched records; $sourceDays is a string like '01', '02', '05', '15', '30'
    public function saveData(array $records, string $sourceDays = ''): bool {
        if (empty($records)) return true;

        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO cases
            (case_number, defendant, dob, sex, charge, crime_category, age_range, source_days)
            VALUES (:case_number, :defendant, :dob, :sex, :charge, :crime_category, :age_range, :source_days)
        ');

        $this->db->exec('BEGIN TRANSACTION');
        try {
            foreach ($records as $r) {
                $stmt->bindValue(':case_number', $r['case_number'] ?? '');
                $stmt->bindValue(':defendant', $r['defendant'] ?? '');
                $stmt->bindValue(':dob', $r['dob'] ?? '');
                $stmt->bindValue(':sex', $r['sex'] ?? '');
                $stmt->bindValue(':charge', $r['charge'] ?? '');
                $stmt->bindValue(':crime_category', $r['crime_category'] ?? 'OTHER');
                $stmt->bindValue(':age_range', $r['age_range'] ?? 'Unknown');
                $stmt->bindValue(':source_days', $sourceDays);
                $stmt->execute();
            }
            $this->db->exec('COMMIT');
            return true;
        } catch (Exception $e) {
            $this->db->exec('ROLLBACK');
            error_log('Database saveData error: ' . $e->getMessage());
            return false;
        }
    }

    public function getCrimeStats(string $sourceDays = ''): array {
        if ($sourceDays === '') {
            $result = $this->db->query('
                SELECT IFNULL(crime_category, "UNKNOWN") AS type, COUNT(*) AS count
                FROM cases
                GROUP BY crime_category
                ORDER BY count DESC
            ');
        } else {
            $stmt = $this->db->prepare('
                SELECT IFNULL(crime_category, "UNKNOWN") AS type, COUNT(*) AS count
                FROM cases
                WHERE source_days = :sd
                GROUP BY crime_category
                ORDER BY count DESC
            ');
            $stmt->bindValue(':sd', $sourceDays);
            $result = $stmt->execute();
        }
        return $this->fetchAll($result);
    }

    public function getAgeStats(string $sourceDays = ''): array {
        if ($sourceDays === '') {
            $result = $this->db->query('
                SELECT IFNULL(age_range, "Unknown") AS range, COUNT(*) AS count
                FROM cases
                GROUP BY age_range
                ORDER BY range
            ');
        } else {
            $stmt = $this->db->prepare('
                SELECT IFNULL(age_range, "Unknown") AS range, COUNT(*) AS count
                FROM cases
                WHERE source_days = :sd
                GROUP BY age_range
                ORDER BY range
            ');
            $stmt->bindValue(':sd', $sourceDays);
            $result = $stmt->execute();
        }
        return $this->fetchAll($result);
    }

    public function getSexStats(string $sourceDays = ''): array {
        if ($sourceDays === '') {
            $result = $this->db->query('
                SELECT CASE WHEN sex IS NULL OR sex = "" THEN "Unknown"
                            WHEN sex = "M" THEN "Male"
                            WHEN sex = "F" THEN "Female"
                            ELSE sex END AS type,
                       COUNT(*) AS count
                FROM cases
                GROUP BY type
            ');
        } else {
            $stmt = $this->db->prepare('
                SELECT CASE WHEN sex IS NULL OR sex = "" THEN "Unknown"
                            WHEN sex = "M" THEN "Male"
                            WHEN sex = "F" THEN "Female"
                            ELSE sex END AS type,
                       COUNT(*) AS count
                FROM cases
                WHERE source_days = :sd
                GROUP BY type
            ');
            $stmt->bindValue(':sd', $sourceDays);
            $result = $stmt->execute();
        }
        return $this->fetchAll($result);
    }

    public function getLastUpdate(string $sourceDays = ''): ?string {
        if ($sourceDays === '') {
            $res = $this->db->querySingle('SELECT created_at FROM cases ORDER BY created_at DESC LIMIT 1');
        } else {
            $stmt = $this->db->prepare('SELECT created_at FROM cases WHERE source_days = :sd ORDER BY created_at DESC LIMIT 1');
            $stmt->bindValue(':sd', $sourceDays);
            $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            $res = $res ? $res['created_at'] : null;
        }
        return $res ? $res : null;
    }

    private function fetchAll($result): array {
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }
}