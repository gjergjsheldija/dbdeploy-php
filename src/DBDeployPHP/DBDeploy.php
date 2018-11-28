<?php
/**
 * DBDeploy PHP
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace DBDeployPHP;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clone of DBDeploy for PHP and Doctrine DBAL.
 *
 * Only supports a limited set of functionality of the orignal DBDeploy.
 * Notable things not supported:
 *
 * - Output of the changes done
 * - UNDO syntax
 */
class DBDeploy
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    private $connection;

    /**
     * @var string
     */
    private $schemaDirectory;

    public function __construct(Connection $connection, $schemaDirectory)
    {
        if (!is_dir($schemaDirectory)) {
            throw new \RuntimeException(sprintf('SchemaDirectory "%s" variable is not a valid directory.', $schemaDirectory));
        }

        $this->connection      = $connection;
        $this->schemaDirectory = $schemaDirectory;
    }

    /**
     * Return Current Migration Status of the database.
     *
     * @return MigrationStatus
     */
    public function getCurrentStatus()
    {
        $schemaManager = $this->connection->getSchemaManager();
        $tables        = $schemaManager->listTableNames();

        if (!in_array('changelog', $tables)) {
            $table = new \Doctrine\DBAL\Schema\Table('changelog');
            $table->addColumn('change_number', 'integer');
            $table->addColumn('complete_dt', 'datetime', [
                'columnDefinition' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ]);
            $table->addColumn('applied_by', 'string', ['length' => 100]);
            $table->addcolumn('description', 'string', ['length' => 500]);
            $table->setPrimaryKey(['change_number']);

            $schemaManager->createTable($table);
        }

        $allMigrations     = $this->getAllMigrations($this->schemaDirectory);
        $appliedMigrations = $this->getAppliedMigrations();
        $applyMigrations   = [];

        foreach ($allMigrations as $revision => $data) {
            if (!isset($appliedMigrations[$revision])) {
                $applyMigrations[$revision] = $data;
            }
        }

        return new MigrationStatus($allMigrations, $appliedMigrations, $applyMigrations);
    }

    /**
     * Migrate database to new version comparing changelog table and schema directory.
     *
     * @return array<string,array>
     */
    public function migrate()
    {
        $status = $this->getCurrentStatus();
        $this->apply($status);

        return $status->getApplyMigrations();
    }

    /**
     * Apply a migration status with unapplied changes to the database.
     *
     * @param MigrationStatus $status
     * @param boolean         $showSql
     * @param OutputInterface $output
     */
    public function apply(MigrationStatus $status, bool $showSql, OutputInterface $output)
    {
        $this->connection->beginTransaction();
        foreach ($status->getApplyMigrations() as $revision => $data) {
            if ($showSql === true) {
                $output->writeln($data['sql']);
            }
            $this->connection->exec($data['sql']);
            $this->connection->insert(
                'changelog',
                [
                    'change_number' => $data['change_number'],
                    'description'   => $data['description'],
                    'applied_by'    => $data['applied_by'],
                ]
            );
        }
        $this->connection->commit();
    }

    private function getAllMigrations($path)
    {
        $files      = glob($path . '/*.sql');
        $migrations = [];

        foreach ($files as $file) {
            $basefile = basename($file);
            $sql      = file_get_contents($file);

            $revision = $this->getRevision($basefile);

            if (isset($migrations[$revision])) {
                throw new \RuntimeException(sprintf("Duplicate revision number '%d' is not allowed.", $revision));
            }

            if (strpos($sql, '--//@UNDO') !== false) {
                throw new \RuntimeException('No support for DBDeploy "--//@UNDO" feature.');
            }

            $migrations[$revision] = [
                'change_number' => $revision,
                'sql'           => $sql,
                'file'          => $file,
                'description'   => $basefile,
                'applied_by'    => $this->connection->getUsername(),
            ];
        }

        ksort($migrations, SORT_NATURAL);

        return $migrations;
    }

    private function getAppliedMigrations()
    {
        $appliedMigrations = [];

        $sql  = 'SELECT * FROM changelog';
        $stmt = $this->connection->executeQuery($sql);

        while ($row = $stmt->fetch()) {
            $revision = $this->getRevision($row['description']);

            if (isset($appliedMigrations[$revision])) {
                throw new \RuntimeException(sprintf("Duplicate revision number '%d' is not allowed.", $revision));
            }

            $appliedMigrations[$revision] = $row;
        }

        return $appliedMigrations;
    }

    private function getRevision($basefile)
    {
        if (preg_match('((^[0-9]+)\ -\ (.*)$)', $basefile, $matches)) {
            return $matches[1];
        } else {
            throw new \RuntimeException(sprintf("No revision found in file '%s'.", $basefile));
        }
    }
}
