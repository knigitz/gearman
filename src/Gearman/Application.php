<?php
namespace Sinergi\Gearman;

use GearmanJob;
use Closure;
use Exception;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as Loop;
use React\EventLoop\LibEventLoop;
use React\EventLoop\StreamSelectLoop;
use Serializable;
use Sinergi\Gearman\Exception\InvalidBootstrapClassException;

class Application implements Serializable
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Process
     */
    private $process;

    /**
     * @var array
     */
    private $callbacks = [];

    /**
     * @var StreamSelectLoop|LibEventLoop
     */
    private $loop;

    /**
     * @var bool|resource
     */
    private $lock = false;

    /**
     * @var bool
     */
    private $kill = false;

    /**
     * @var Worker
     */
    private $worker;

    /**
     * @var array
     */
    private $jobs = [];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $isAllowingJob = false;

    /**
     * @param Config $config
     * @param StreamSelectLoop|LibEventLoop $loop
     * @param Process $process
     * @param LoggerInterface|null $logger
     */
    public function __construct(Config $config = null, Process $process = null, $loop = null, LoggerInterface $logger = null)
    {
        if (null === $config) {
            $config = Config::getInstance();
        }
        $this->setConfig($config);

        if (null !== $logger) {
            $this->setLogger($logger);
        }

        if (null !== $process) {
            $this->setProcess($process);
        }
        if ($loop instanceof StreamSelectLoop || $loop instanceof LibEventLoop) {
            $this->setLoop($loop);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            if (null !== $this->logger) {
                $this->logger->info("Stopped GearmanWorker Server");
            }
            $this->getProcess()->release($this->lock);
        }
    }

    public function restart()
    {
        $serialized = serialize($this);
        $file = realpath(__DIR__ . "/../../bin/gearman_restart");

        $serializedFile = sys_get_temp_dir() . '/gearman_restart_' . uniqid();
        file_put_contents($serializedFile, $serialized);

        if ($file && is_executable($file)) {
            pcntl_exec($file, ['serialized' => $serializedFile]);
            exit;
        } elseif ($file) {
            $dir = dirname($file);
            $content = file_get_contents($dir . '/gearman_restart_template');
            $content = str_replace('%path', $dir . '/gearman_restart.php', $content);
            $newFile = sys_get_temp_dir() . '/gearman_restart_' . uniqid();
            file_put_contents($newFile, $content);
            chmod($newFile, 0755);
            pcntl_exec($newFile, ['serialized' => $serializedFile]);
            unlink($newFile);
            exit;
        }
    }

    /**
     * @param bool $fork
     * @throws InvalidBootstrapClassException
     */
    public function run($fork = true)
    {
        if ($this->getConfig()->getEnvVariables()) {
            $this->addEnvVariables();
        }

        $bootstrap = $this->getConfig()->getBootstrap();
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }

        $class = $this->getConfig()->getClass();
        if (!empty($class)) {
            $bootstrap = new $class();
            if (!$bootstrap instanceof BootstrapInterface) {
                throw new InvalidBootstrapClassException;
            }
            $bootstrap->run($this);
        }

        $this->runProcess($fork);
    }

    public function addEnvVariables()
    {
        foreach ($this->getConfig()->getEnvVariables() as $key => $variable) {
            $key = (string)$key;
            $variable = (string)$variable;
            $var = "{$key}={$variable}";
            putenv($var);
        }
    }

    /**
     * @param bool $fork
     * @throws Exception
     */
    public function runProcess($fork = true)
    {
        $pidFile = $this->getProcess()->getPidFile();
        $lockFile = $this->getProcess()->getLockFile();
        if (is_file($pidFile) && is_writable($pidFile)) {
            unlink($pidFile);
        }
        if (is_file($lockFile) && is_writable($lockFile)) {
            unlink($lockFile);
        }

        $this->changeUser();

        if ($fork) {
            $pid = pcntl_fork();
        }

        if (!$fork || (isset($pid) && $pid !== -1 && !$pid)) {
            $this->getProcess()->setPid(posix_getpid());

            if (isset($pid) && $pid !== -1 && !$pid) {
                $parentPid = posix_getppid();
                if ($parentPid) {
                    posix_kill(posix_getppid(), SIGUSR2);
                }
            }

            $this->lock = $this->getProcess()->lock();

            if (null !== $this->logger) {
                $this->logger->info("Started GearmanWorker Server");
            }

            $this->signalHandlers();
            $this->createLoop();
        } elseif ($fork && isset($pid) && $pid) {
            $wait = true;

            pcntl_signal(SIGUSR2, function () use (&$wait) {
                $wait = false;
            });

            while ($wait) {
                pcntl_waitpid($pid, $status, WNOHANG);
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * @throws Exception
     */
    private function changeUser()
    {
        $user = $this->getConfig()->getUser();
        if ($user) {
            $user = posix_getpwnam($user);
            if (posix_geteuid() !== (int)$user['uid']) {
                posix_setgid($user['gid']);
                posix_setuid($user['uid']);
                if (posix_geteuid() !== (int)$user['uid']) {
                    $message = "Unable to change user to {$user['uid']}";
                    if (null !== $this->logger) {
                        $this->logger->error($message);
                    }
                    throw new Exception($message);
                }
            }
        }
    }

    /**
     * @return $this
     */
    private function signalHandlers()
    {
        $root = $this;
        pcntl_signal(SIGUSR1, function () use ($root) {
            $root->setKill(true);
        });
        return $this;
    }

    /**
     * @return $this
     */
    private function createLoop()
    {
        $worker = $this->getWorker()->getWorker();
        $worker->setTimeout(10);

        $callbacks = $this->getCallbacks();

        while ($worker->work() || $worker->returnCode() == GEARMAN_TIMEOUT) {
            if ($this->getKill()) {
                break;
            }

            pcntl_signal_dispatch();

            if (count($callbacks)) {
                foreach ($callbacks as $callback) {
                    $callback($this);
                }
            }
        }

        return $this;
    }

    /**
     * @param JobInterface $job
     * @param GearmanJob $gearmanJob
     * @return mixed
     */
    public function executeJob(JobInterface $job, GearmanJob $gearmanJob)
    {
        if ($this->getConfig()->getAutoUpdate() && !$this->isAllowingJob) {
            $this->restart();
            return null;
        }
        $this->isAllowingJob = false;
        if (null !== $this->logger) {
            $this->logger->info("Executing job {$job->getName()}");
        }
        return $job->execute($gearmanJob);
    }

    /**
     * @return Worker
     */
    public function getWorker()
    {
        if (null === $this->worker) {
            $this->setWorker(new Worker($this->getConfig(), $this->getLogger()));
        }
        return $this->worker;
    }

    /**
     * @param Worker $worker
     * @return $this
     */
    public function setWorker(Worker $worker)
    {
        $this->worker = $worker;
        return $this;
    }

    /**
     * @param JobInterface $job
     * @return $this
     */
    public function add(JobInterface $job)
    {
        $worker = $this->getWorker();
        $this->jobs[] = $job;
        $root = $this;
        $worker->addFunction($job->getName(), function (\GearmanJob $gearmanJob) use ($root, $job) {
            return $root->executeJob($job, $gearmanJob);
        });
        return $this;
    }

    /**
     * @return array
     */
    public function getJobs()
    {
        return $this->jobs;
    }

    /**
     * @param callable $callback
     * @return $this
     */
    public function addCallback(Closure $callback)
    {
        $this->callbacks[] = $callback;
        return $this;
    }

    /**
     * @return array
     */
    public function getCallbacks()
    {
        return $this->callbacks;
    }

    /**
     * @param StreamSelectLoop|LibEventLoop $loop
     * @return $this
     */
    public function setLoop($loop)
    {
        $this->loop = $loop;
        return $this;
    }

    /**
     * @return LibEventLoop|StreamSelectLoop
     */
    public function getLoop()
    {
        if (null === $this->loop) {
            $this->setLoop(Loop::create());
        }
        return $this->loop;
    }

    /**
     * @return bool
     */
    public function getKill()
    {
        return $this->kill;
    }

    /**
     * @param $kill
     * @return $this
     */
    public function setKill($kill)
    {
        $this->kill = $kill;
        return $this;
    }

    /**
     * @param Config $config
     * @return $this
     */
    public function setConfig(Config $config)
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        if (null === $this->config) {
            $this->setConfig(new Config);
        }
        return $this->config;
    }

    /**
     * @param Process $process
     * @return $this
     */
    public function setProcess(Process $process)
    {
        $this->process = $process;
        return $this;
    }

    /**
     * @return Process
     */
    public function getProcess()
    {
        if (null === $this->process) {
            $this->setProcess(new Process($this->getConfig(), $this->getLogger()));
        }
        return $this->process;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @return string
     */
    public function serialize()
    {
        return serialize([
            'config' => $this->getConfig(),
            'isAllowingJob' => true
        ]);
    }

    /**
     * @param string $serialized
     */
    public function unserialize($serialized)
    {
        $data = unserialize($serialized);

        if (isset($data['config'])) {
            $this->setConfig($data['config']);
        }

        $process = new Process($this->getConfig(), $this->getLogger());
        $this->setProcess($process);

        if (isset($data['isAllowingJob'])) {
            $this->isAllowingJob = $data['isAllowingJob'];
        }
    }
}
