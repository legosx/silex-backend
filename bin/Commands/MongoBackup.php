<?php

namespace App\Commands;

use App\Helpers\Config;
use Symfony\Component\Console\Command\Command;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ErrorException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use DateTime;

class MongoBackup extends Command
{
    protected function configure()
    {
        $this
            ->setName('mongo:backup')
            ->setDescription('Backup mongo to file')
            ->setHelp('This command create backup of mongo database')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('gzip', 'g', InputOption::VALUE_NONE, 'Use gzip to backup'),
                    new InputOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit backup files, old will be deleted')
                ])
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!($mongo_url = Arr::get(Config::env(), 'MONGO_DB_URL'))) {
            throw new ErrorException('MONGO_DB_URL is required in .env');
        }

        $output->writeln([
            'Mongo Backup',
            '============'
        ]);

        $db_name = ltrim(parse_url($mongo_url, PHP_URL_PATH), '/');
        $backup_dir = realpath(__DIR__ . '/../../backup');

        if ($limit = $input->getOption('limit')) {
            $stat = [];
            $old = glob($backup_dir . DIRECTORY_SEPARATOR . $db_name . '-[0-9]???-[0-9]?-[0-9]?-[0-9]?-[0-9]?-[0-9]?.{archive,gz}', GLOB_BRACE);
            foreach ($old as $file) {
                $d = explode('-', pathinfo($file, PATHINFO_FILENAME));
                $stat[mktime($d[4], $d[5], $d[6], $d[2], $d[3], $d[1])] = $file;
            }

            krsort($stat);
            $stat = array_slice($stat, $limit - 1);
            foreach ($stat as $file) {
                @unlink($file);
            }
        }

        $gzip = $input->getOption('gzip');
        $path = $backup_dir . DIRECTORY_SEPARATOR . $db_name . '-' . date('Y-m-d-H-i-s') . ($gzip ? '.gz' : '.archive');

        $host = parse_url($mongo_url, PHP_URL_HOST);
        $port = parse_url($mongo_url, PHP_URL_PORT);

        $run = 'mongodump -h ' . $host . ':' . $port . ' -d ' . $db_name . ' --archive="' . $path . '"' . ($gzip ? ' --gzip' : '');

        $process = new Process($run);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output->writeln($process->getOutput());

        if (!file_exists($path)) {
            throw new ErrorException('Output file not exists');
        } else {
            $output->writeln('Backup of database "' . $db_name . '" created:' . PHP_EOL . $path);
        }

        $output->writeln([
            '',
            'END'
        ]);
    }
}