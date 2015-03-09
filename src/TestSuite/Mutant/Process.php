<?php

/**
 *
 * @category   Humbug
 * @package    Humbug
 * @copyright  Copyright (c) 2015 Pádraic Brady (http://blog.astrumfutura.com)
 * @license    https://github.com/padraic/humbug/blob/master/LICENSE New BSD License
 * @author     Thibaud Fabre
 */
namespace Humbug\TestSuite\Mutant;

use Humbug\Adapter\AdapterAbstract;
use Humbug\Exception\RuntimeException;
use Humbug\Mutant;
use Symfony\Component\Process\PhpProcess;

class Process
{

    const STATUS_OK = 0;

    const STATUS_ERROR = 1;

    const STATUS_TIMEOUT = 2;

    const STATUS_FAILED = 3;

    /**
     * @var AdapterAbstract
     */
    private $adapter;

    /**
     * @var Mutant
     */
    private $mutant;

    /**
     * @var int
     */
    private $mutableIndex;

    /**
     * @var PhpProcess
     */
    private $process;

    /**
     * @var bool
     */
    private $resultProcessed = false;

    /**
     * @var int
     */
    private $status = 0;

    /**
     * @param AdapterAbstract $adapter
     * @param Mutant $mutant
     * @param PhpProcess $process
     */
    public function __construct(AdapterAbstract $adapter, Mutant $mutant, PhpProcess $process, $index)
    {
        $this->adapter = $adapter;
        $this->mutant = $mutant;
        $this->process = $process;
        $this->mutableIndex = $index;
    }

    /**
     * @return AdapterAbstract
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * @return Mutant
     */
    public function getMutant()
    {
        return $this->mutant;
    }

    /**
     * @return PhpProcess
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * @return int
     */
    public function getMutableIndex()
    {
        return $this->mutableIndex;
    }

    /**
     * @return Result
     */
    public function getResult()
    {
        if ($this->resultProcessed) {
            throw new RuntimeException('Result has already been processed.');
        }

        $result = new Result(
            $this->mutant,
            $this->getStatusCode(),
            $this->process->getOutput(),
            $this->process->getErrorOutput()
        );

        $this->process->clearOutput();

        $this->resultProcessed = true;

        return $result;
    }

    private function getStatusCode()
    {
        switch ($this->status) {
            case self::STATUS_TIMEOUT:
                return Result::TIMEOUT;
            case self::STATUS_ERROR:
                return Result::ERROR;
            case self::STATUS_FAILED:
                return Result::KILL;
            case self::STATUS_OK:
            default:
                return Result::ESCAPE;
        }
    }

    /**
     * Marks the process as timed out;
     */
    public function markTimeout()
    {
        $this->status = self::STATUS_TIMEOUT;
    }

    /**
     * Marks the process as having an error.
     */
    public function markErrored()
    {
        $this->status = self::STATUS_ERROR;
    }

    /**
     * Updates the process status from output
     * @param string $data
     */
    public function updateStatusFromOutput($data)
    {
        if ($this->status != self::STATUS_OK) {
            return;
        }

        if (! $this->adapter->ok($data)) {
            $this->status = self::STATUS_FAILED;
        }
    }
}
