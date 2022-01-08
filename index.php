<?php

/**
 * Class Task
 */
class Task
{

    protected $taskId;

    /** @var Generator */
    protected $coroutine;

    protected $sendValue = null;

    /**
     * @var bool
     */
    protected $beforeFirstYield = true;

    public function __construct($taskId, Generator $coroutine)
    {
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

    public function run()
    {
        if ($this->beforeFirstYield) {
            $this->beforeFirstYield = false;
            return $this->coroutine->current();
        } else {
            $returnValue = $this->coroutine->send($this->sendValue);
            $this->sendValue = null;
            return $returnValue;
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
class Scheduler
{
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

    public function killTask($taskId)
    {
        if (!isset($this->taskMap[$taskId])) {
            return false;
        }

        unset($this->taskMap[$taskId]);
        foreach ($this->taskQueue as $i => $task) {
            if ($task->getTaskId() === $taskId) {
                unset($this->taskQueue[$i]);
                break;
            }
        }
        return true;
    }

    public function run()
    {
        while (!$this->taskQueue->isEmpty()) {
            /** @var Task $task */
            $task = $this->taskQueue->dequeue();
            /** @var SystemCall $returnValue */
            $returnValue = $task->run();

            if ($returnValue instanceof SystemCall) {
                $returnValue($task, $this); // Pass task, scheduler to SystemCall
                continue;
            }

            if ($task->isFinished()) {
                unset($this->taskMap[$task->getTaskId()]);
            } else {
                $this->schedule($task);
            }
        }
    }
}

/**
 * Class SystemCall
 */
class SystemCall
{
    protected $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * It will run, when a object to call as a function
     * @param Task $task
     * @param Scheduler $scheduler
     */
    public function __invoke(Task $task, Scheduler $scheduler)
    {
        $callback = $this->callback;
        $callback($task, $scheduler);
    }
}

function getTaskId()
{
    return new SystemCall(function (Task $task, Scheduler $scheduler) {
        $task->setSendValue($task->getTaskId());
        $scheduler->schedule($task);
    });
}

function newTask(Generator $coroutine)
{
    return new SystemCall(
        function (Task $task, Scheduler $scheduler) use ($coroutine) {
            $task->setSendValue($scheduler->newTask($coroutine));
            $scheduler->schedule($task);
        }
    );
}

function killTask($tid)
{
    return new SystemCall(
        function (Task $task, Scheduler $scheduler) use ($tid) {
            $task->setSendValue($scheduler->killTask($tid));
            $scheduler->schedule($task);
        }
    );
}

function task1($max)
{
    $tid = yield getTaskId(); // <-- here's the syscall!
    for ($i = 1; $i <= $max; ++$i) {
        echo "This is task $tid iteration $i.\n";
        yield;
    }
}

function task2($max)
{
    $tid = yield getTaskId(); // <-- here's the syscall!
    for ($i = 1; $i <= $max; ++$i) {
        echo "This is task $tid iteration $i.\n";
        yield;
    }
}

function childTask()
{
    $tid = (yield getTaskId());
    while (true) {
        echo "Child task $tid still alive!\n";
        yield;
    }
}

function task3()
{
    $tid = (yield getTaskId());
    $childTid = (yield newTask(childTask()));

    for ($i = 1; $i <= 6; ++$i) {
        echo "Parent task $tid iteration $i.\n";
        yield;

        if ($i == 3) yield killTask($childTid);
    }
}

$time_start = microtime(true);
echo "Running... \n";

$scheduler = new Scheduler;

$scheduler->newTask(task1(4));
$scheduler->newTask(task2(4));
$scheduler->newTask(task3());
$scheduler->run();

$time_end = microtime(true);
$execution_time = ($time_end - $time_start);

echo "Total Execution Time:\n " . $execution_time . "\n";

