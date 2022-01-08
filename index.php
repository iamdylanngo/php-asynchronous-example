<?php

/**
 * Class Task
 */
class Task {

    protected $taskId;

    /** @var Generator */
    protected $coroutine;

    protected $sendValue = null;

    /**
     * @var bool
     */
    protected $beforeFirstYield = true;

    public function __construct($taskId, Generator $coroutine) {
        $this->taskId = $taskId;
        $this->coroutine = $coroutine;
    }

    /**
     * @return mixed
     */
    public function getTaskId()
    {
        return $this->taskId;
    }

    /**
     * @param null $sendValue
     */
    public function setSendValue($sendValue): void
    {
        $this->sendValue = $sendValue;
    }

    public function run() {
        if ($this->beforeFirstYield) {
            $this->beforeFirstYield = false;
            return $this->coroutine->current();
        } else {
            $receiveVal = $this->coroutine->send($this->sendValue);
            $this->sendValue = null;
            return $receiveVal;
        }
    }

    public function isFinished()
    {
        return !$this->coroutine->valid();
    }

}

/**
 * Class Scheduler
 */
class Scheduler {
    protected $maxTaskId = 0;
    protected $taskMap = [];
    protected $taskQueue;

    public function __construct()
    {
        $this->taskQueue = new SplQueue();
    }

    public function schedule(Task $task)
    {
        $this->taskQueue->enqueue($task);
    }

    public function newTask(Generator $coroutine)
    {
        $taskId = ++$this->maxTaskId;
        $task = new Task($taskId, $coroutine);
        $this->taskMap[$taskId] = $task;
        $this->schedule($task);
        return $taskId;
    }

    public function run()
    {
        while (!$this->taskQueue->isEmpty()) {
            /** @var Task $task */
            $task = $this->taskQueue->dequeue();
            $task->run();

            if ($task->isFinished()) {
                unset($this->taskMap[$task->getTaskId()]);
            } else {
                $this->schedule($task);
            }
        }
    }
}

function task1() {
    for ($i = 1; $i <= 1000; ++$i) {
        echo "This is task 1 iteration $i.\n";
        yield;
    }
}

function task2() {
    for ($i = 1; $i <= 1000000; ++$i) {
        echo "This is task 2 iteration $i.\n";
        yield;
    }
}

function task3() {
    for ($i = 1; $i <= 1000000; ++$i) {
        echo "This is task 3 iteration $i.\n";
        yield;
    }
}

$time_start = microtime(true);
echo "Running... \n";

$scheduler = new Scheduler;

$scheduler->newTask(task1());
$scheduler->newTask(task2());
$scheduler->newTask(task3());
$scheduler->run();

$time_end = microtime(true);
$execution_time = ($time_end - $time_start);

echo "Total Execution Time:\n ".$execution_time . "\n";

