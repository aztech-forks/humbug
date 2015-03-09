<?php
/**
 * Humbug
 *
 * @category   Humbug
 * @package    Humbug
 * @copyright  Copyright (c) 2015 Pádraic Brady (http://blog.astrumfutura.com)
 * @license    https://github.com/padraic/humbug/blob/master/LICENSE New BSD License
 */

namespace Humbug\Utility;

use Humbug\TestSuite\Mutant\Process;
use \Symfony\Component\Process\Process as SfProcess;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ParallelGroup
{
    /**
     * @var Process[]
     */
    protected $processes = [];

    public function __construct(array $processes)
    {
        $this->processes = $processes;
    }

    public function run()
    {
        foreach ($this->processes as $process) {
            $process->getProcess()->start(function ($out, $data) use ($process) {
                if ($out == SfProcess::ERR) {
                    $process->markErrored();
                }
                else {
                    $process->updateStatusFromOutput($data);
                }
            });
        }

        usleep(1000);
        while ($this->stillRunning()) {
            usleep(1000);
        }
        $this->processes = [];
    }

    public function stillRunning()
    {
        foreach ($this->processes as $index => $process) {
            try {
                $process->getProcess()->checkTimeout();
            } catch (ProcessTimedOutException $e) {
                $process->markTimeout();
            }
            if ($process->getProcess()->isRunning()) {
                return true;
            }
        }
    }

    public function reset()
    {
        $this->processes = [];
    }
}
