<?php

class ApiHandler extends DBTable {

    public function __construct($app) {
        parent::__construct();
        $this->app = $app;
    }

    public function index()
        {
        //$this->app->get("/page/{name}/{lng}", array ($this, 'renderPage'));
        }

    }
