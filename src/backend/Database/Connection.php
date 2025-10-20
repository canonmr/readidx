<?php

namespace Readidx\Backend\Database;

use PDO;
use PDOException;
use RuntimeException;

class Connection
{
    private array $config;
    private ?PDO $pdo = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = $this->config['driver'] ?? 'mysql';

        try {
            if ($driver === 'sqlite') {
                $dsn = sprintf('sqlite:%s', $this->config['database']);
                $this->pdo = new PDO($dsn, null, null, $this->config['options'] ?? []);
                return $this->pdo;
            }

            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $driver,
                $this->config['host'] ?? '127.0.0.1',
                $this->config['port'] ?? '3306',
                $this->config['database'] ?? '',
                $this->config['charset'] ?? 'utf8mb4'
            );

            $this->pdo = new PDO(
                $dsn,
                $this->config['username'] ?? '',
                $this->config['password'] ?? '',
                $this->config['options'] ?? []
            );

            return $this->pdo;
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to connect to the database: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
