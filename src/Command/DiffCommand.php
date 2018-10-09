<?php

namespace Gamegos\ConsulImex\Command;

/* Imports from symfony/console */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Consul Diff
 * @author Emirhan Marlalı <emirhan@gamegos.com>
 */
class DiffCommand extends Command
{
    /**
     * Construct.
     */
    public function __construct()
    {
        parent::__construct('diff');
        $this->setDescription('Export diff data between Consul key-value services.');
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'Source prefix.');
        $this->addArgument('other-source', InputArgument::REQUIRED, 'Other source prefix.');
        $this->addArgument('file', InputArgument::REQUIRED, 'Export file.');
        $this->addOption('source-server', 's', InputOption::VALUE_REQUIRED, 'Source server URL.');
        $this->addOption('other-server', 'o', InputOption::VALUE_REQUIRED, 'Other source server URL. If omitted, source server is used as other source server.');
        $this->addOption('consul-token', 'c', InputOption::VALUE_OPTIONAL, 'Consul Token.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Create temporary file.
        $source = tempnam(sys_get_temp_dir(), 'CIX');
        $sourceExportParams = [
            'command'  => 'export',
            'file'     => $source,
            '--prefix' => $input->getArgument('source'),
        ];

        if ($input->getOption('source-server') !== null) {
            $sourceExportParams['--url'] = $input->getOption('source-server');
        }
        if ($input->getOption('consul-token') !== null) {
            $sourceExportParams['--consul-token'] = $input->getOption('consul-token');
        }

        // Prepare the 'export' command.
        $sourceExportCommand = $this->getApplication()->find('export');

        $sourceExportReturn = $sourceExportCommand->run(new ArrayInput($sourceExportParams), $output);
        if (0 !== $sourceExportReturn) {
            return $sourceExportReturn;
        }

        // Create temporary file.
        $target = tempnam(sys_get_temp_dir(), 'CIX');
        $targetExportParams = [
            'command'  => 'export',
            'file'     => $target,
            '--prefix' => $input->getArgument('other-source'),
        ];

        if ($input->getOption('other-server') !== null) {
            $targetExportParams['--url'] = $input->getOption('other-server');
        } elseif ($input->getOption('source-server') !== null) {
            $targetExportParams['--url'] = $input->getOption('source-server');
        }

        if ($input->getOption('consul-token') !== null) {
            $targetExportParams['--consul-token'] = $input->getOption('consul-token');
        }
        // Prepare the 'export' command.
        $targetExportCommand = $this->getApplication()->find('export');

        $targetExportReturn = $targetExportCommand->run(new ArrayInput($targetExportParams), $output);
        if (0 !== $targetExportReturn) {
            return $targetExportReturn;
        }

        $sourceContent = @ json_decode(file_get_contents($source), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($sourceContent)) {
            throw new DiffException('Unexpected source json decode error.');
        }

        $targetContent = @ json_decode(file_get_contents($target), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($targetContent)) {
            throw new DiffException('Unexpected target json decode error.');
        }

        $data = static::arrayRecursiveDiff($sourceContent, $targetContent);

        $file   = $input->getArgument('file');
        $handle = @ fopen($file, 'wb');
        if (false === $handle) {
            throw new DiffException(sprintf('Cannot open file for writing (%s).', $file));
        }
        if (!fwrite($handle, json_encode($data, JSON_PRETTY_PRINT))) {
            throw new DiffException(sprintf('Cannot write file (%s).', $file));
        }

        $output->writeln('<info>Operation completed.</info>');
    }

    /**
     * Recursively diff two arrays. This function expects the leaf levels to be
     * arrays of strings or null
     *
     * @param array $source
     * @param array $other
     * @return array
     * @see https://stackoverflow.com/a/3877494
     */
    protected static function arrayRecursiveDiff(array $source, array $other)
    {
        $difference = array();
        foreach ($source as $key => $value) {
            if (array_key_exists($key, $other)) {
                if (is_array($value)) {
                    $diff = static::arrayRecursiveDiff($value, $other[$key]);
                    if (count($diff)) { $difference[$key] = $diff; }
                } else {
                    if ($value != $other[$key]) {
                        $difference[$key] = $value;
                    }
                }
            } else {
                $difference[$key] = $value;
            }
        }
        return $difference;
    }
}
