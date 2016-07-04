<?php

class TokenAuth extends \Slim\Middleware {

    public function __construct() {
        
    }

    /**
     * Deny Access
     *
     */
    public function deny_access() {
        header('Content-Type: application/json');
        $res = $this->app->response();
        $res->headers->set('Content-Type', 'application/json');
        $res->status(401);
        echo json_encode(array('error' => true, 'status' => 401, 'msg' => "No access"));
    }

    /**
     * Check against the DB if the token is valid
     * 
     * @param string $token
     * @return bool
     */
    public function authenticate($token) {
        return (new \Authentication ($this->app))->validateToken($token);
    }

    /**
     * Call
     *
     */
    public function call() {
        if (preg_match('#^/?log(in|out)([?/].+)?$#', $this->app->request->getResourceUri()) > 0)
            {
            $this->next->call();
            return;
            }
           
        //Get the token sent from jquery
        $tokenAuth = \Authentication::getAuthorizationToken ($this->app);

        //Check if our token is valid
        $userObject = $this->authenticate($tokenAuth);
        if ($userObject) {
            $this->app->auth_user = $userObject;
            //Continue with execution
            $this->next->call();
        } else {
            $this->deny_access();
        }
    }

}