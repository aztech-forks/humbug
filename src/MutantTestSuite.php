<?php

namespace Humbug;

use Humbug\Exception\NoCoveringTestsException;
use Humbug\Renderer\Text;
use Humbug\Utility\CoverageData;
use Humbug\Utility\ParallelGroup;

class MutantTestSuite
{

    private $mutables;

    private $mutableCount;

    private $threadCount;

    /**
     * @var MutantTestSuiteObserver[]
     */
    private $observers = [];

    public function __construct(array $mutables, $threadCount = 1)
    {
        $this->mutables = $mutables;
        $this->mutableCount = count($mutables);
        $this->threadCount = max((int) $threadCount, 1);
    }

    public function getMutableCount()
    {
        return $this->mutableCount;
    }

    public function getMutables()
    {
        return $this->mutables;
    }

    public function addObserver(MutantTestSuiteObserver $observer)
    {
        $this->observers[] = $observer;
    }

    public function run(Container $container, CoverageData $coverage)
    {
        $this->onStartRun();

        $collector = new Collector();

        /**
         * MUTATION TESTING!
         */
        foreach ($this->mutables as $i => $mutable) {
            $mutations = $mutable->generate()->getMutations();
            $batches = array_chunk($mutations, $this->threadCount);

            try {
                $coverage->loadCoverageFor($mutable->getFilename());
            } catch (NoCoveringTestsException $e) {
                foreach ($mutations as $mutation) {
                    $collector->collectShadow();
                    $this->onShadowMutant($i);
                }
                continue;
            }

            foreach ($batches as $batch) {
                $this->runBatch($container, $collector, $coverage, $batch, $i);
            }

            $mutable->cleanup();
        }

        $coverage->cleanup();
        $this->onEndRun($collector);
    }

    /**
     * @param Container $container
     * @param Collector $collector
     * @param CoverageData $coverage
     * @param $batch
     * @param $i
     */
    private function runBatch(
        Container $container,
        Collector $collector,
        CoverageData $coverage,
        $batch,
        $i
    ) {
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
                $this->onShadowMutant($i);
            }
        }

        /**
         * Check if the whole batch has been eliminated as uncovered
         * by any tests
         */
        if (count($processes) == 0) {
            return;
        }

        $group = new ParallelGroup($processes);
        $group->run();

        foreach ($mutants as $tracker => $mutant) {
            /**
             * Handle the defined result for each process
             */
            $result = $mutant->getResult($group->timedOut($tracker));

            $mutant->getProcess()->clearOutput();
            $this->onMutantDone($mutant, $result, $i);
            $collector->collect($mutant, $result);
        }
    }


    private function onStartRun()
    {
        foreach ($this->observers as $observer) {
            $observer->onStartRun($this);
        }
    }

    private function onShadowMutant($index)
    {
        foreach ($this->observers as $observer) {
            $observer->onShadowMutant($this, $index);
        }
    }

    private function onMutantDone(Mutant $mutant, MutantResult $result, $index)
    {
        foreach ($this->observers as $observer) {
            $observer->onMutantDone($this, $mutant, $result, $index);
        }
    }

    private function onEndRun(Collector $collector)
    {
        foreach ($this->observers as $observer) {
            $observer->onEndRun($this, $collector);
        }
    }

}