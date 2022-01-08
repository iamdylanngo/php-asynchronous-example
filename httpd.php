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

    // resourceID => [socket, tasks]
    protected $waitingForRead = [];
    protected $waitingForWrite = [];

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

    public function waitForRead($socket, Task $task) {
        if (isset($this->waitingForRead[(int) $socket])) {
            $this->waitingForRead[(int) $socket][1][] = $task;
        } else {
            $this->waitingForRead[(int) $socket] = [$socket, [$task]];
        }
    }

    public function waitForWrite($socket, Task $task) {
        if (isset($this->waitingForWrite[(int) $socket])) {
            $this->waitingForWrite[(int) $socket][1][] = $task;
        } else {
            $this->waitingForWrite[(int) $socket] = [$socket, [$task]];
        }
    }

    protected function ioPoll($timeout) {
        $rSocks = [];
        foreach ($this->waitingForRead as list($socket)) {
            $rSocks[] = $socket;
        }

        $wSocks = [];
        foreach ($this->waitingForWrite as list($socket)) {
            $wSocks[] = $socket;
        }

        $eSocket = [];

        if (!stream_select($rSocks, $wSocks, $eSocket, $timeout)) {
            return;
        }

        foreach ($rSocks as $socket) {
            list(, $tasks) = $this->waitingForRead[(int) $socket];
            unset($this->waitingForRead[(int) $socket]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }

        foreach ($wSocks as $socket) {
            list(, $tasks) = $this->waitingForWrite[(int) $socket];
            unset($this->waitingForWrite[(int) $socket]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }
    }

    protected function ioPollTask(): Generator
    {
        while (true) {
            if ($this->taskQueue->isEmpty()) {
                $this->ioPoll(null);
            } else {
                $this->ioPoll(0);
            }
            yield;
        }
    }

    public function run()
    {
        $this->newTask($this->ioPollTask());
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

function getTaskId(): SystemCall
{
    return new SystemCall(function (Task $task, Scheduler $scheduler) {
        $task->setSendValue($task->getTaskId());
        $scheduler->schedule($task);
    });
}

function newTask(Generator $coroutine): SystemCall
{
    return new SystemCall(
        function (Task $task, Scheduler $scheduler) use ($coroutine) {
            $task->setSendValue($scheduler->newTask($coroutine));
            $scheduler->schedule($task);
        }
    );
}

function killTask($tid): SystemCall
{
    return new SystemCall(
        function (Task $task, Scheduler $scheduler) use ($tid) {
            $task->setSendValue($scheduler->killTask($tid));
            $scheduler->schedule($task);
        }
    );
}

function waitForRead($socket): SystemCall
{
    return new SystemCall(
        function(Task $task, Scheduler $scheduler) use ($socket) {
            $scheduler->waitForRead($socket, $task);
        }
    );
}

function waitForWrite($socket): SystemCall
{
    return new SystemCall(
        function(Task $task, Scheduler $scheduler) use ($socket) {
            $scheduler->waitForWrite($socket, $task);
        }
    );
}

/**
 * @throws Exception
 */
function server($port): Generator
{
    echo "Starting server at port $port...\n";

    $socket = @stream_socket_server("tcp://0.0.0.0:$port", $errNo, $errStr);
    if (!$socket) throw new Exception($errStr, $errNo);

    stream_set_blocking($socket, 0);

    while (true) {
        yield waitForRead($socket);
        $clientSocket = stream_socket_accept($socket, 0);
        yield newTask(handleClient($clientSocket));
    }
}

function handleClient($socket) {
    yield waitForRead($socket);
    $data = fread($socket, 8192);

    for ($i = 0; $i < 10000; $i++) {

    }

    $msg = "Received following request:\n\n$data";
    $msgLength = strlen($msg);

    $response = <<<RES
HTTP/1.1 200 OK\r
Content-Type: text/plain\r
Content-Length: $msgLength\r
Connection: close\r
\r
$msg
RES;

    yield waitForWrite($socket);
    fwrite($socket, $response);

    fclose($socket);
}

$scheduler = new Scheduler;
$scheduler->newTask(server(8000));
$scheduler->run();