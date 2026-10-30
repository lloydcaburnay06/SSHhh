<?php

declare(strict_types=1);

namespace Deployer;

use PDO;

final class EventLogger
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{
     *   project_id?: int|null,
     *   project_name?: string,
     *   category: string,
     *   event_type: string,
     *   status: string,
     *   title: string,
     *   ssh_target?: string|null,
     *   command_text?: string|null,
     *   output_log?: string|null,
     *   steps?: array<int|string, mixed>|null,
     *   exit_code?: int|null,
     *   duration_ms?: int|null,
     *   completed?: bool
     * } $data
     */
    public function log(array $data): int
    {
        $completed = $data['completed'] ?? ($data['status'] !== 'running');
        $stepsJson = isset($data['steps'])
            ? json_encode($data['steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO `event_logs` (
                `project_id`,
                `project_name`,
                `category`,
                `event_type`,
                `status`,
                `title`,
                `ssh_target`,
                `command_text`,
                `output_log`,
                `steps_json`,
                `exit_code`,
                `duration_ms`,
                `completed_at`
            ) VALUES (
                :project_id,
                :project_name,
                :category,
                :event_type,
                :status,
                :title,
                :ssh_target,
                :command_text,
                :output_log,
                :steps_json,
                :exit_code,
                :duration_ms,
                :completed_at
            )
        SQL);

        $stmt->execute([
            ':project_id'   => $data['project_id'] ?? null,
            ':project_name' => (string) ($data['project_name'] ?? ''),
            ':category'     => $data['category'],
            ':event_type'   => $data['event_type'],
            ':status'       => $data['status'],
            ':title'        => mb_substr($data['title'], 0, 255),
            ':ssh_target'   => isset($data['ssh_target']) ? mb_substr((string) $data['ssh_target'], 0, 300) : null,
            ':command_text' => $data['command_text'] ?? null,
            ':output_log'   => $data['output_log'] ?? null,
            ':steps_json'   => $stepsJson,
            ':exit_code'    => $data['exit_code'] ?? null,
            ':duration_ms'  => $data['duration_ms'] ?? null,
            ':completed_at' => $completed ? date('Y-m-d H:i:s') : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{
     *   status: string,
     *   title?: string,
     *   output_log?: string|null,
     *   steps?: array<int, mixed>|null,
     *   exit_code?: int|null,
     *   duration_ms?: int|null
     * } $update
     */
    public function finalize(int $eventId, array $update): void
    {
        $fields = [
            '`status` = :status',
            '`completed_at` = :completed_at',
        ];
        $params = [
            ':id'           => $eventId,
            ':status'       => $update['status'],
            ':completed_at' => date('Y-m-d H:i:s'),
        ];

        if (isset($update['title'])) {
            $fields[] = '`title` = :title';
            $params[':title'] = mb_substr($update['title'], 0, 255);
        }
        if (array_key_exists('output_log', $update)) {
            $fields[] = '`output_log` = :output_log';
            $params[':output_log'] = $update['output_log'];
        }
        if (array_key_exists('steps', $update)) {
            $fields[] = '`steps_json` = :steps_json';
            $params[':steps_json'] = $update['steps'] !== null
                ? json_encode($update['steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
        }
        if (array_key_exists('exit_code', $update)) {
            $fields[] = '`exit_code` = :exit_code';
            $params[':exit_code'] = $update['exit_code'];
        }
        if (array_key_exists('duration_ms', $update)) {
            $fields[] = '`duration_ms` = :duration_ms';
            $params[':duration_ms'] = $update['duration_ms'];
        }

        $sql = 'UPDATE `event_logs` SET ' . implode(', ', $fields) . ' WHERE `id` = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function get(int $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `event_logs` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $eventId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    /**
     * @param array{category?:string,status?:string,search?:string,limit?:int,offset?:int} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, counts: array<string, int>}
     */
    public function query(?int $projectId = null, array $filters = []): array
    {
        $where = [];
        $params = [];

        if ($projectId !== null) {
            $where[] = '`project_id` = :project_id';
            $params[':project_id'] = $projectId;
        }

        // Category counts before category/status filtering (scoped to project if provided)
        $counts = [
            'all'        => 0,
            'deployment' => 0,
            'command'    => 0,
            'ssh'        => 0,
            'audit'      => 0,
        ];
        $countWhere = $projectId !== null ? 'WHERE `project_id` = :project_id' : '';
        $countStmt = $this->pdo->prepare(
            "SELECT `category`, COUNT(*) AS `cnt` FROM `event_logs` {$countWhere} GROUP BY `category`"
        );
        $countStmt->execute($projectId !== null ? [':project_id' => $projectId] : []);
        foreach ($countStmt->fetchAll() as $cRow) {
            $cat = (string) $cRow['category'];
            $cnt = (int) $cRow['cnt'];
            $counts['all'] += $cnt;
            if (isset($counts[$cat])) {
                $counts[$cat] = $cnt;
            }
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '' && $category !== 'all') {
            $where[] = '`category` = :category';
            $params[':category'] = $category;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $where[] = '`status` = :status';
            $params[':status'] = $status;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(`title` LIKE :q1 OR `command_text` LIKE :q2 OR `output_log` LIKE :q3 OR `ssh_target` LIKE :q4)';
            $like = '%' . $search . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $totalStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `event_logs` {$whereSql}");
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $listSql = "SELECT * FROM `event_logs` {$whereSql} ORDER BY `id` DESC LIMIT {$limit} OFFSET {$offset}";
        $listStmt = $this->pdo->prepare($listSql);
        $listStmt->execute($params);

        $items = [];
        foreach ($listStmt->fetchAll() as $row) {
            $items[] = $this->hydrateRow($row);
        }

        return [
            'items'  => $items,
            'total'  => $total,
            'counts' => $counts,
        ];
    }

    public function clearForProject(int $projectId, string $projectName): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM `event_logs` WHERE `project_id` = :project_id');
        $stmt->execute([':project_id' => $projectId]);
        $deleted = $stmt->rowCount();

        $this->log([
            'project_id'   => $projectId,
            'project_name' => $projectName,
            'category'     => 'audit',
            'event_type'   => 'logs_cleared',
            'status'       => 'info',
            'title'        => sprintf('Cleared %d historical event log entries', $deleted),
        ]);

        return $deleted;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $steps = null;
        if (!empty($row['steps_json']) && is_string($row['steps_json'])) {
            $decoded = json_decode($row['steps_json'], true);
            if (is_array($decoded)) {
                $steps = $decoded;
            }
        }
        unset($row['steps_json']);

        $row['id'] = (int) $row['id'];
        $row['project_id'] = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        $row['exit_code'] = $row['exit_code'] !== null ? (int) $row['exit_code'] : null;
        $row['duration_ms'] = $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null;
        $row['steps'] = $steps;

        return $row;
    }
}

