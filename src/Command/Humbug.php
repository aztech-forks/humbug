<?php
/**
 * Humbug
 *
 * @category   Humbug
 * @package    Humbug
 * @copyright  Copyright (c) 2015 Pádraic Brady (http://blog.astrumfutura.com)
 * @license    https://github.com/padraic/humbug/blob/master/LICENSE New BSD License
 */

namespace Humbug\Command;

use Humbug\Adapter\AdapterAbstract;
use Humbug\Collector;
use Humbug\Config;
use Humbug\Config\JsonParser;
use Humbug\Container;
use Humbug\Adapter\Phpunit;
use Humbug\Mutant;
use Humbug\MutantResult;
use Humbug\ProcessRunner;
use Humbug\Report\Text as TextReport;
use Humbug\TestSuiteResult;
use Humbug\Utility\Performance;
use Humbug\Utility\ParallelGroup;
use Humbug\Renderer\Text;
use Humbug\Exception\InvalidArgumentException;
use Humbug\Exception\NoCoveringTestsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput as EmptyInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpProcess;

class Humbug extends Command
{
    protected $container;

    protected $finder;

    private $jsonLogFile;

    private $textLogFile;

    /**
     * Execute the command.
     * The text output, other than some newline management, is held within
     * Humbug\Renderer\Text.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Performance::upMemProfiler();
        $output->writeln($this->getApplication()->getLongVersion() . PHP_EOL);

        $this->validate($input);
        $container = $this->container = new Container($input->getOptions());

        $this->doConfiguration();

        if ($this->isLoggingEnabled()) {
            $this->removeOldLogFiles();
        } else {
            $output->writeln('<error>No log file is specified. Detailed results will not be available.</error>');
        }

        $formatterHelper = new FormatterHelper;
        if ($this->textLogFile) {
            $renderer = new Text($output, $formatterHelper, true);
        } else {
            $renderer = new Text($output, $formatterHelper);
        }

        $renderer->renderPreTestIntroduction();
        $output->write(PHP_EOL);

        /**
         * Log buffered renderer output to file if enabled
         */
        $this->logText($renderer);

        /**
         * Make initial test run to ensure tests are in a starting passing state
         * and also log the results so test runs during the mutation phase can
         * be optimised.
         */
        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('verbose');
        $progressBar->setBarWidth(58);

        if (!$output->isDecorated()) {
            $progressBar->setRedrawFrequency(60);
        }

        $progressBar->start();
        $testFrameworkAdapter = $container->getAdapter();
        $process = $testFrameworkAdapter->getProcess($container, true);
        $hasFailure = $this->performInitialTestsRun($process, $testFrameworkAdapter, $progressBar);

        $progressBar->finish();
        $output->write(PHP_EOL.PHP_EOL);

        $result = new TestSuiteResult($process, $container, '/coverage.humbug.txt');

        /**
         * Check if the initial test run ended with a fatal error
         */
        if ($result->isFailure() || $hasFailure) {
            $renderer->renderInitialRunFail($result, $hasFailure);
            $this->logText($renderer);
            return 1;
        }

        /**
         * Initial test run was a success!
         */
        $renderer->renderInitialRunPass($result, $progressBar->getProgress());
        $output->write(PHP_EOL);
        $this->logText($renderer);

        /**
         * Message re Static Analysis
         */
        $renderer->renderStaticAnalysisStart();
        $output->write(PHP_EOL);

        /**
         * Examine all source code files and collect up mutations to apply
         */
        $coverage = $result->getCoverage();
        $mutables = $container->getMutableFiles($this->finder);

        /**
         * Message re Mutation Testing starting
         */
        $renderer->renderMutationTestingStart(count($mutables));
        $output->write(PHP_EOL);
        Performance::start();
        $this->logText($renderer);

        /**
         * Iterate across all mutations. After each, run the test suite and
         * collect data on how tests handled the mutations. We use ext/runkit
         * to dynamically alter included (in-memory) classes on the fly.
         */
        $collector = new Collector();

        /**
         * We can do parallel runs, but typically two test runs will compete for
         * any uninsulated resources (e.g. files/database) so hardcoded to 1 for now.
         *
         * TODO: Move PHPUnit specific stuff to adapter...
         */
        $parallels = 1;

        /**
         * MUTATION TESTING!
         */
        foreach ($mutables as $i => $mutable) {
            $mutations = $mutable->generate()->getMutations();
            $batches = array_chunk($mutations, $parallels);
            unset($mutations);

            try {
                $coverage->loadCoverageFor($mutable->getFilename());
            } catch (NoCoveringTestsException $e) {
                foreach ($batches as $batch) {
                    $collector->collectShadow();
                    $renderer->renderShadowMark(count($mutables), $i);
                }
                continue;
            }

            foreach ($batches as $batch) {
                $mutants = [];
                $processes = [];
                // Being utterly paranoid, track index using $tracker explicitly
                // to ensure process->mutation indices are linked for reporting.
                foreach ($batch as $tracker => $mutation) {
                    try {
                        /**
                         * Unleash the Mutant!
                         */
                        $mutants[$tracker] = new Mutant($mutation, $container, $coverage);

                        $processes[$tracker] = $mutants[$tracker]->getProcess();
                    } catch (NoCoveringTestsException $e) {
                        /**
                         * No tests excercise the mutated line. We'll report
                         * the uncovered mutants separately and omit them
                         * from final score.
                         */
                        $collector->collectShadow();
                        $renderer->renderShadowMark(count($mutables), $i);
                    }
                }

                /**
                 * Check if the whole batch has been eliminated as uncovered
                 * by any tests
                 */
                if (count($processes) == 0) {
                    continue;
                }

                $group = new ParallelGroup($processes);
                $group->run();

                foreach ($mutants as $tracker => $mutant) {
                    $process = $mutant->getProcess();

                    /**
                     * Define the result for each process
                     */
                    $result = new MutantResult(
                        $container->getAdapter()->ok($process->getOutput()),
                        $process->isSuccessful(),
                        $group->timedOut($tracker),
                        $process->getOutput(),
                        $process->getErrorOutput()
                    );

                    $process->clearOutput();

                    /**
                     * Handle the defined result for each process
                     */

                    $renderer->renderProgressMark($result, count($mutables), $i);
                    $this->logText($renderer);

                    $collector->collect($mutant, $result);
                }
            }

            $mutable->cleanup();
        }

        $coverage->cleanup();
        Performance::stop();

        /**
         * Render summary report with stats
         */
        $output->write(PHP_EOL);
        $renderer->renderSummaryReport($collector);
        $output->write(PHP_EOL);

        /**
         * Do any detailed logging now
         */
        if ($this->jsonLogFile) {
            $renderer->renderLogToJson($this->jsonLogFile);
            $this->logJson($collector);
        }

        if ($this->textLogFile) {
            $renderer->renderLogToText($this->textLogFile);
            $this->logText($renderer);

            $textReport = $this->prepareTextReport($collector);
            $this->logText($renderer, $textReport);
        }

        if ($this->jsonLogFile || $this->textLogFile) {
            $output->write(PHP_EOL);
        }

        /**
         * Render performance data
         */
        $renderer->renderPerformanceData(Performance::getTimeString(), Performance::getMemoryUsageString());
        $this->logText($renderer);

        Performance::downMemProfiler();
    }

    protected function logJson(Collector $collector)
    {
        $vanquishedTotal = $collector->getVanquishedTotal();
        $measurableTotal = $collector->getMeasurableTotal();

        if ($measurableTotal !== 0) {
            $detectionRateTested  = round(100 * ($vanquishedTotal / $measurableTotal));
        } else {
            $detectionRateTested  = 0;
        }

        if ($collector->getTotalCount() !== 0) {
            $uncoveredRate = round(100 * ($collector->getShadowCount() / $collector->getTotalCount()));
            $detectionRateAll = round(100 * ($collector->getVanquishedTotal() / $collector->getTotalCount()));
        } else {
            $uncoveredRate = 0;
            $detectionRateAll = 0;
        }
        $out = [
            'summary' => [
                'total' => $collector->getTotalCount(),
                'kills' => $collector->getKilledCount(),
                'escapes' => $collector->getEscapeCount(),
                'errors' => $collector->getErrorCount(),
                'timeouts' => $collector->getTimeoutCount(),
                'notests' => $collector->getShadowCount(),
                'covered_score' => $detectionRateTested,
                'combined_score' => $detectionRateAll,
                'mutation_coverage' => (100 - $uncoveredRate)
            ],
            'escaped' => []
        ];

        $out = array_merge($out, $collector->toGroupedMutantArray());

        file_put_contents(
            $this->jsonLogFile,
            json_encode($out, JSON_PRETTY_PRINT)
        );
    }

    protected function prepareFinder($directories, $excludes)
    {
        $finder = new Finder;
        $finder->files()->name('*.php');

        if ($directories) {
            foreach ($directories as $directory) {
                $finder->in($directory);
            }
        } else {
            $finder->in('.');
        }

        if (isset($excludes)) {
            foreach ($excludes as $exclude) {
                $finder->exclude($exclude);
            }
        }

        return $finder;
    }

    protected function doConfiguration()
    {
        $this->container->setBaseDirectory(getcwd());

        $config = (new JsonParser())->parseFile('humbug.json');

        $newConfig = new Config($config);

        $source = $newConfig->getSource();

        $this->finder = $this->prepareFinder(
            isset($source->directories)? $source->directories : null,
            isset($source->excludes)? $source->excludes : null
        );

        $this->container->setSourceList($source);

        $timeout = $newConfig->getTimeout();

        if ($timeout !== null) {
            $this->container->setTimeout((int) $timeout);
        }

        $chDir = $newConfig->getChDir();

        if ($chDir !== null) {
            $this->container->setTestRunDirectory($chDir);
        }

        $this->jsonLogFile = $newConfig->getLogsJson();
        $this->textLogFile = $newConfig->getLogsText();
    }

    protected function configure()
    {
        $this
            ->setName('run')
            ->setDescription('Run Humbug for target tests')
            ->addOption(
               'adapter',
               'a',
               InputOption::VALUE_REQUIRED,
               'Set name of the test adapter to use.',
                'phpunit'
            )
            ->addOption(
               'options',
               'o',
               InputOption::VALUE_REQUIRED,
               'Set command line options string to pass to test adapter. '
                    . 'Default is dictated dynamically by '.'Humbug'.'.'
            )
            ->addOption(
               'constraints',
               'c',
               InputOption::VALUE_REQUIRED,
               'Options set on adapter to constrain which tests are run. '
                    . 'Applies only to the very first initialising test run.'
            )
            ->addOption(
               'timeout',
               't',
               InputOption::VALUE_REQUIRED,
               'Sets a timeout applied for each test run to combat infinite loop mutations.',
                10
            );
    }

    private function validate(InputInterface $input)
    {
        /**
         * Adapter
         */
        if ($input->getOption('adapter') !== 'phpunit') {
            throw new InvalidArgumentException(
                'Only a PHPUnit adapter is supported at this time. Sorry!'
            );
        }
        /**
         * Timeout
         */
        if (!is_numeric($input->getOption('timeout')) || $input->getOption('timeout') <= 0) {
            throw new InvalidArgumentException(
                'The timeout must be an integer specifying a number of seconds. '
                . 'A number greater than zero is expected, and greater than maximum '
                . 'test suite execution time under any given constraint option is '
                . 'highly recommended.'
            );
        }
    }

    private function logText(Text $renderer, $output = null)
    {
        if ($this->textLogFile) {
            $logText = !is_null($output) ? $output : $renderer->getBuffer();

            file_put_contents(
                $this->textLogFile,
                $logText,
                FILE_APPEND
            );
        }
    }

    private function removeOldLogFiles()
    {
        if (file_exists($this->jsonLogFile)) {
            unlink($this->jsonLogFile);
        }

        if (file_exists($this->textLogFile)) {
            unlink($this->textLogFile);
        }
    }

    private function isLoggingEnabled()
    {
        return $this->jsonLogFile !== null || $this->textLogFile !== null;
    }

    private function prepareTextReport(Collector $collector)
    {
        $textReport = new TextReport();

        $out = $textReport->prepareMutantsReport($collector->getEscaped(), 'Escapes');

        if ($collector->getTimeoutCount() > 0) {
            $out .= PHP_EOL . $textReport->prepareMutantsReport($collector->getTimeouts(), 'Timeouts');
        }

        if ($collector->getErrorCount() > 0) {
            $out .= PHP_EOL . $textReport->prepareMutantsReport($collector->getErrors(), 'Errors');
        }

        return $out;
    }

    private function performInitialTestsRun(
        PhpProcess $process,
        AdapterAbstract $testFrameworkAdapter,
        ProgressBar $progressBar
    ) {
        $setProgressBarProgressCallback = function ($count) use ($progressBar) {
            $progressBar->setProgress($count);
        };

        return (new ProcessRunner())->run($process, $testFrameworkAdapter, $setProgressBarProgressCallback);
    }
}
