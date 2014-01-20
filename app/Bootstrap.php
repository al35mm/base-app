<?php

/**
 * Bootstrap
 *
 * @package     base-app
 * @category    Application
 * @version     2.0
 */

namespace Baseapp;

use \Baseapp\Library\I18n,
    \Baseapp\Library\Debug,
    \Baseapp\Library\Email;

class Bootstrap extends \Phalcon\Mvc\Application
{

    private $_di;
    private $_config;

    /**
     * Constructor
     *
     * @param $di
     */
    public function __construct(\Phalcon\DiInterface $di)
    {
        $this->_di = $di;

        $loaders = array('config', 'loader', 'timezone', 'lang', 'db', 'flash', 'crypt', 'session', 'cookie', 'cache', 'url', 'router');

        // Register services
        foreach ($loaders as $service)
            $this->$service();

        // Register modules
        $this->registerModules(array(
            'frontend' => array(
                'className' => 'Baseapp\Frontend\Module',
                'path' => ROOT_PATH . '/app/frontend/Module.php'
            ),
            'backend' => array(
                'className' => 'Baseapp\Backend\Module',
                'path' => ROOT_PATH . '/app/backend/Module.php'
            )
        ));

        // Register the app itself as a service
        $this->_di->set('app', $this);

        // Sets the parent Di
        parent::setDI($this->_di);
    }

    protected function loader()
    {
        // Register an autoloader
        $loader = new \Phalcon\Loader();
        $loader->registerNamespaces(array(
            'Baseapp\Models' => ROOT_PATH . '/app/common/models/',
            'Baseapp\Library' => ROOT_PATH . '/app/common/library/',
            'Baseapp\Extension' => ROOT_PATH . '/app/common/extension/'
        ))->register();
    }

    protected function config()
    {
        // Create the new object
        $config = new \Phalcon\Config\Adapter\Ini(ROOT_PATH . '/app/common/config/config.ini');

        // Store it in the Di container
        $this->_di->set('config', $config);
        $this->_config = $config;
    }

    protected function timezone()
    {
        date_default_timezone_set($this->_config->app->timezone);
    }

    protected function lang()
    {
        I18n::instance()->lang();
    }

    protected function crypt()
    {
        $config = $this->_config;

        $this->_di->set('crypt', function() use ($config) {
            $crypt = new \Phalcon\Crypt();
            $crypt->setKey($config->crypt->key);
            return $crypt;
        });
    }

    protected function cookie()
    {
        $this->_di->set('cookies', function() {
            $cookies = new \Phalcon\Http\Response\Cookies();
            return $cookies;
        });
    }

    protected function db()
    {
        $config = $this->_config;
        // Set the database service
        $this->_di->set('db', function() use ($config) {
            return new \Phalcon\Db\Adapter\Pdo\Mysql(array(
                "host" => $config->database->host,
                "username" => $config->database->username,
                "password" => $config->database->password,
                "dbname" => $config->database->dbname
            ));
        });
    }

    protected function flash()
    {
        $this->_di->set('flashSession', function() {
            $flash = new \Phalcon\Flash\Session(array(
                'warning' => 'alert alert-warning',
                'notice' => 'alert alert-info',
                'success' => 'alert alert-success',
                'error' => 'alert alert-danger',
                'dismissable' => 'alert alert-dismissable',
            ));
            return $flash;
        });
    }

    protected function session()
    {
        // Start the session the first time some component request the session service
        $this->_di->set('session', function() {
            $session = new \Phalcon\Session\Adapter\Files();
            $session->start();
            return $session;
        });
    }

    protected function cache()
    {
        $config = $this->_config;
        // Register all cache services from config
        foreach ($config->cache->services as $service => $section) {
            $this->_di->set($service, function() use ($config, $section) {
                // Load settings for section
                $frontend = $config->$section;
                $backend = $config->{$frontend->backend};
                // Set adapters
                $adapterFrontend = "\Phalcon\Cache\Frontend\\" . $frontend->adapter;
                $adapterBackend = "\Phalcon\Cache\Backend\\" . $backend->adapter;
                // Set cache
                $frontCache = new $adapterFrontend(get_object_vars($frontend->options));
                $cache = new $adapterBackend($frontCache, get_object_vars($backend->options));

                return $cache;
            });
        }
    }

    protected function url()
    {
        $config = $this->_config;
        $this->_di->set('url', function() use ($config) {
            $url = new \Phalcon\Mvc\Url();
            $url->setBaseUri($config->app->base_uri);
            $url->setStaticBaseUri($config->app->static_uri);
            return $url;
        });
    }

    protected function router()
    {
        // Setting up the static router
        $this->_di->set('router', function() {
            $router = new \Phalcon\Mvc\Router(FALSE);

            $router->setDefaultModule('frontend');
            $router->setDefaultController('index');
            $router->setDefaultAction('index');


            $router->add('/:controller/:action/:params', array(
                'module' => 'frontend',
                'controller' => 1,
                'action' => 2,
                'params' => 3,
            ));

            $router->add('/:controller/:int', array(
                'module' => 'frontend',
                'controller' => 1,
                'action' => 'index',
                'id' => 2,
            ));

            $router->add('/:controller[/]?', array(
                'module' => 'frontend',
                'controller' => 1,
                'action' => 'index'
            ));

            $router->add('/', array(
                'module' => 'frontend',
                'controller' => 'index',
                'action' => 'index'
            ));

            $router->add('/admin/:controller/:action/:params', array(
                'module' => 'backend',
                'controller' => 1,
                'action' => 2,
                'params' => 3,
            ));

            $router->add('/admin/:controller[/]?', array(
                'module' => 'backend',
                'controller' => 1,
                'action' => 'index',
            ));

            $router->add('/admin[/]?', array(
                'module' => 'backend',
                'controller' => 'index',
                'action' => 'index',
            ));

            $router->notFound(array(
                'controller' => 'index',
                'action' => 'notFound'
            ));

            return $router;
        });
    }

    /**
     * Does a HMVC request in the application
     *
     * @param array $location
     * @param array $data
     * @return mixed
     */
    public function request($location, $data = null)
    {
        $dispatcher = clone $this->getDI()->get('dispatcher');

        if (isset($location['controller']))
            $dispatcher->setControllerName($location['controller']);
        else
            $dispatcher->setControllerName('index');

        if (isset($location['action']))
            $dispatcher->setActionName($location['action']);
        else
            $dispatcher->setActionName('index');

        if (isset($location['params']))
            if (is_array($location['params']))
                $dispatcher->setParams($location['params']);
            else
                $dispatcher->setParams((array) $location['params']);
        else
            $dispatcher->setParams(array());

        $dispatcher->dispatch();

        $response = $dispatcher->getReturnedValue();
        if ($response instanceof \Phalcon\Http\ResponseInterface)
            return $response->getContent();

        return $response;
    }

    public static function log($messages)
    {
        $config = \Phalcon\DI::getDefault()->getShared('config');

        if ($config->app->env == "development") {
            foreach ($messages as $key => $message)
                echo Debug::dump($message, $key);
            exit();
        } else {
            $logger = new \Phalcon\Logger\Adapter\File(ROOT_PATH . '/app/common/logs/' . date('Ymd') . '.log', array('mode' => 'a+'));
            $email = new Email();
            $log = '';

            foreach ($messages as $key => $message) {
                if (in_array($key, array('alert', 'debug', 'error', 'info', 'notice', 'warning')))
                    $logger->$key($message);
                else
                    $logger->log($message);
                $log .= Debug::dump($message, $key);
            }
            $logger->close();
            $email->prepare(__('Something is wrong!'), $config->app->admin, 'error', array('log' => $log));
            $email->Send();
        }
    }

    public static function exception(\Exception $e)
    {
        $config = \Phalcon\DI::getDefault()->getShared('config');

        if ($config->app->env == "development") {
            // Display debug output
            $debug = new \Phalcon\Debug();
            $debug->onUncaughtException($e);
        } else {
            // Display pretty view of the error
            $di = new \Phalcon\DI\FactoryDefault();
            $view = new \Phalcon\Mvc\View\Simple();
            $view->setDI($di);
            $view->setViewsDir(ROOT_PATH . '/app/frontend/views/');
            $view->registerEngines(\Baseapp\Library\Tool::registerEngines($view, $di));
            echo $view->render('error');

            // Log errors to file and send email with errors to admin
            $errors = array(
                'error' => get_class($e) . '[' . $e->getCode() . ']: ' . $e->getMessage(),
                'info' => $e->getFile() . '[' . $e->getLine() . ']',
                'debug' => "Trace: \n" . $e->getTraceAsString() . "\n",
            );
            \Baseapp\Bootstrap::log($errors);
        }
    }

}
