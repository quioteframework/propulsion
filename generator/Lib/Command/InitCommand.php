<?php

namespace Propulsion\Generator\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;

#[AsCommand(
    name: 'init',
    description: 'Initialize a new Propulsion project'
)]
class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Project name')
            ->setHelp('Initialize a new Propulsion project with basic structure and configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Propulsion Project Initializer');
        
        // Get project name
        $projectName = $input->getArgument('name');
        if (!$projectName) {
            $question = new Question('Enter project name', 'my-propel-project');
            $projectName = $io->askQuestion($question);
        }
        
        // Ask for database platform
        $platformQuestion = new ChoiceQuestion(
            'Choose database platform',
            ['mysql', 'postgresql', 'sqlite', 'oracle', 'mssql'],
            'mysql'
        );
        $platform = $io->askQuestion($platformQuestion);
        
        // Create project structure
        $this->createProjectStructure($projectName, $platform, $io);
        
        $io->success("Project '$projectName' initialized successfully!");
        $io->text([
            '',
            'Next steps:',
            '1. Edit schema/schema.xml to define your data model',
            '2. Update propel.json with your database connection details',
            '3. Run: propel model:build',
            '4. Run: propel sql:build',
            '5. Run: propel sql:insert',
        ]);
        
        return Command::SUCCESS;
    }
    
    private function createProjectStructure(string $projectName, string $platform, SymfonyStyle $io): void
    {
        $dirs = [
            $projectName,
            "$projectName/schema",
            "$projectName/generated-classes",
            "$projectName/generated-sql"
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                $io->text("Created directory: $dir");
            }
        }
        
        // Create configuration
        $config = [
            'propel' => [
                'database' => [
                    'connections' => [
                        'default' => [
                            'adapter' => $platform,
                            'classname' => 'Propulsion\\Connection\\PropulsionPDO',
                            'dsn' => $this->generateDsn($platform, $projectName),
                            'user' => 'your_username',
                            'password' => 'your_password'
                        ]
                    ]
                ],
                'generator' => [
                    'defaultConnection' => 'default',
                    'schema' => [
                        'dir' => './schema'
                    ],
                    'php' => [
                        'dir' => './generated-classes'
                    ],
                    'sql' => [
                        'dir' => './generated-sql'
                    ]
                ]
            ]
        ];
        
        file_put_contents("$projectName/propel.json", json_encode($config, JSON_PRETTY_PRINT));
        $io->text("Created configuration: $projectName/propel.json");
        
        // Create sample schema
        $this->createSampleSchema($projectName, $io);
    }
    
    private function generateDsn(string $platform, string $projectName): string
    {
        return match($platform) {
            'mysql' => "mysql:host=localhost;dbname=$projectName",
            'postgresql' => "pgsql:host=localhost;dbname=$projectName",
            'sqlite' => "sqlite:$projectName.db",
            'oracle' => "oci:dbname=//localhost:1521/$projectName",
            'mssql' => "sqlsrv:Server=localhost;Database=$projectName",
            default => "mysql:host=localhost;dbname=$projectName"
        };
    }
    
    private function createSampleSchema(string $projectName, SymfonyStyle $io): void
    {
        $schema = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<database name="$projectName" defaultIdMethod="native">
    <table name="user" phpName="User">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="username" type="VARCHAR" size="255" required="true" />
        <column name="email" type="VARCHAR" size="255" required="true" />
        <column name="password_hash" type="VARCHAR" size="255" required="true" />
        <column name="created_at" type="TIMESTAMP" />
        <column name="updated_at" type="TIMESTAMP" />
        
        <unique>
            <unique-column name="username" />
        </unique>
        <unique>
            <unique-column name="email" />
        </unique>
        
        <behavior name="timestampable" />
    </table>
    
    <table name="post" phpName="Post">
        <column name="id" required="true" primaryKey="true" autoIncrement="true" type="INTEGER" />
        <column name="user_id" type="INTEGER" required="true" />
        <column name="title" type="VARCHAR" size="255" required="true" />
        <column name="content" type="LONGVARCHAR" />
        <column name="published" type="BOOLEAN" defaultValue="false" />
        <column name="created_at" type="TIMESTAMP" />
        <column name="updated_at" type="TIMESTAMP" />
        
        <foreign-key foreignTable="user" phpName="User">
            <reference local="user_id" foreign="id" />
        </foreign-key>
        
        <behavior name="timestampable" />
    </table>
</database>
XML;
        
        file_put_contents("$projectName/schema/schema.xml", $schema);
        $io->text("Created sample schema: $projectName/schema/schema.xml");
    }
}