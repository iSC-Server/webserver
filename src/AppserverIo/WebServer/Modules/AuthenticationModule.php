<?php

/**
 * \AppserverIo\WebServer\Modules\AuthenticationModule
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @author    Johann Zelger <jz@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/webserver
 * @link      http://www.appserver.io/
 */

namespace AppserverIo\WebServer\Modules;

use AppserverIo\Logger\LoggerUtils;
use AppserverIo\Psr\HttpMessage\Protocol;
use AppserverIo\Psr\HttpMessage\RequestInterface;
use AppserverIo\Psr\HttpMessage\ResponseInterface;
use AppserverIo\WebServer\Interfaces\HttpModuleInterface;
use AppserverIo\Server\Dictionaries\ModuleHooks;
use AppserverIo\Server\Dictionaries\ServerVars;
use AppserverIo\Server\Dictionaries\ModuleVars;
use AppserverIo\Server\Exceptions\ModuleException;
use AppserverIo\Server\Interfaces\RequestContextInterface;
use AppserverIo\Server\Interfaces\ServerContextInterface;

/**
 * Class AuthenticationModule
 *
 * @author    Johann Zelger <jz@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/webserver
 * @link      http://www.appserver.io/
 */
class AuthenticationModule implements HttpModuleInterface
{

    /**
     * Defines the module name
     *
     * @var string
     */
    const MODULE_NAME = 'authentication';

    /**
     * Holds the server context instance
     *
     * @var \AppserverIo\Server\Interfaces\ServerContextInterface
     */
    protected $serverContext;

    /**
     * Holds all authentication instances
     *
     * @var array
     */
    protected $authentications;

    /**
     * The request instance
     *
     * @var \AppserverIo\Psr\HttpMessage\RequestInterface $request
     */
    protected $request;

    /**
     * The response instance
     *
     * @var \AppserverIo\Psr\HttpMessage\ResponseInterface $response
     */
    protected $response;

    /**
     * Holds all authenticationType instances used within server while running
     *
     * @var array
     */
    protected $typeInstances;

    /**
     * Holds an array of all uri patterns to match uri for
     *
     * @var array
     */
    protected $uriPatterns;

    /**
     * Returns the request instance
     *
     * @return \AppserverIo\Psr\HttpMessage\RequestInterface The request instance
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Returns the response instance
     *
     * @return \AppserverIo\Psr\HttpMessage\ResponseInterface The response instance;
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Initiates the module
     *
     * @param \AppserverIo\Server\Interfaces\ServerContextInterface $serverContext The server's context instance
     *
     * @return bool
     * @throws \AppserverIo\Server\Exceptions\ModuleException
     */
    public function init(ServerContextInterface $serverContext)
    {
        $this->serverContext = $serverContext;
        $this->authentications = $serverContext->getServerConfig()->getAuthentications();
        $systemLogger = $serverContext->getLogger(LoggerUtils::SYSTEM);

        // check if authentication are given
        if (is_array($this->authentications)) {
            // init all uri patterns
            foreach ($this->authentications as $uri => $params) {
                $this->uriPatterns[$uri] = $params;
                // try ti get authentication type instance
                try {
                    // pre init types by calling getter in init
                    $this->getAuthenticationTypeInstance($params["type"], $params);
                } catch (\Exception $e) {
                    // log exception as warning to not end up with a server break
                    $systemLogger->warning($e->getMessage());
                }
            }
        }
    }

    /**
     * Returns the server context instance
     *
     * @return \AppserverIo\Server\Interfaces\ServerContextInterface
     */
    public function getServerContext()
    {
        return $this->serverContext;
    }

    /**
     * Returns pre initiated auth type instance by given type
     *
     * @param string $type The authentication type
     * @param array  $data The data got from client for authentication process
     *
     * @return \AppserverIo\Http\Authentication\AuthenticationInterface
     *
     * @throws \AppserverIo\Server\Exceptions\ModuleException
     */
    public function getAuthenticationTypeInstance($type, array $data = array())
    {
        if (! isset($this->typeInstances[$type])) {
            // check if type class does not exist
            if (! class_exists($type)) {
                throw new ModuleException("No auth type found for '$type'", 500);
            }
            // construct type by given class definition and data
            /** @var \AppserverIo\Http\Authentication\AuthenticationInterface $typeInstance */
            $this->typeInstances[$type] = $typeInstance = new $type($data);
        }
        return $this->typeInstances[$type];
    }

    /**
     * Implements module logic for given hook
     *
     * @param \AppserverIo\Psr\HttpMessage\RequestInterface          $request        A request object
     * @param \AppserverIo\Psr\HttpMessage\ResponseInterface         $response       A response object
     * @param \AppserverIo\Server\Interfaces\RequestContextInterface $requestContext A requests context instance
     * @param int                                                    $hook           The current hook to process logic for
     *
     * @return bool
     * @throws \AppserverIo\Server\Exceptions\ModuleException
     */
    public function process(RequestInterface $request, ResponseInterface $response, RequestContextInterface $requestContext, $hook)
    {

        // if false hook is coming do nothing
        if (ModuleHooks::REQUEST_POST !== $hook) {
            return;
        }

        // set req and res object internally
        $this->request = $request;
        $this->response = $response;

        // get server context to local var
        $serverContext = $this->getServerContext();

        // Get the authentications locally so we do not mess with inter-request configuration
        $authentications = $this->authentications;

        // get system logger
        $systemLogger = $serverContext->getLogger(LoggerUtils::SYSTEM);

        // check if there are some volatile rewrite map definitions so add them
        if ($requestContext->hasModuleVar(ModuleVars::VOLATILE_AUTHENTICATIONS)) {
            $volatileAuthentications = $requestContext->getModuleVar(ModuleVars::VOLATILE_AUTHENTICATIONS);
            // merge rewrite maps
            $authentications = array_merge($volatileAuthentications, $authentications);
        }

        // check authentication information if something matches
        foreach ($authentications as $uriPattern => $data) {
            // check if pattern matches uri
            if (preg_match('/' . $uriPattern . '/', $requestContext->getServerVar(ServerVars::X_REQUEST_URI))) {
                // set type Instance to local ref
                $typeInstance = $this->getAuthenticationTypeInstance($data["type"]);

                // check if auth header is not set in coming request headers
                if (! $request->hasHeader(Protocol::HEADER_AUTHORIZATION)) {
                    // send header for challenge authentication against client
                    $response->addHeader(Protocol::HEADER_WWW_AUTHENTICATE, $typeInstance->getAuthenticateHeader());
                    // throw exception for auth required
                    throw new ModuleException(null, 401);
                }

                // init type instance by request
                $typeInstance->init($request->getHeader(Protocol::HEADER_AUTHORIZATION), $request->getMethod());

                try {
                    // check if auth works
                    if ($typeInstance->authenticate()) {
                        // set server vars
                        $requestContext->setServerVar(ServerVars::REMOTE_USER, $typeInstance->getUsername());
                        // break out because everything is fine at this point
                        break;
                    }
                } catch (\Exception $e) {
                    // log exception as warning to not end up with a 500 response which is not wanted here
                    $systemLogger->warning($e->getMessage());
                }

                // send header for challenge authentication against client
                $response->addHeader(Protocol::HEADER_WWW_AUTHENTICATE, $typeInstance->getAuthenticateHeader());
                // throw exception for auth required
                throw new ModuleException(null, 401);
            }
        }
    }

    /**
     * Returns an array of module names which should be executed first
     *
     * @return array The array of module names
     */
    public function getDependencies()
    {
        return array();
    }

    /**
     * Returns the module name
     *
     * @return string The module name
     */
    public function getModuleName()
    {
        return self::MODULE_NAME;
    }

    /**
     * Prepares the module for upcoming request in specific context
     *
     * @return bool
     * @throws \AppserverIo\Server\Exceptions\ModuleException
     */
    public function prepare()
    {
        // nothing to prepare for this module
    }
}
