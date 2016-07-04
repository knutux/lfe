<?php

class UIHandler extends DBTable {

    public function __construct($app) {
        parent::__construct();
        $this->app = $app;
        $this->logger = empty ($app->getContainer()["logger"]) ? false : $app->getContainer()->logger;
    }

    public function index()
        {
        $container = $this->app->getContainer();
        $container['view'] = new \Slim\Views\PhpRenderer(TEMPLATES_DIR);

        $this->app->get("/page/{name}/{lng}", array ($this, 'renderPage'));
        $this->app->get("/style/{css}", array ($this, 'renderStyleSheets'));
        }

    public function getModel ($page)
        {
        if ($this->logger) $this->logger->debug("UIHandler::getModel $name");
        $model = new StdClass ();
        $model->title = "Page $page";
        $model->meta = array ('Description' => "LFE", "Author" => "knutux@lfe.lt", "Keywords" => "LFE", "ROBOTS" => "INDEX, FOLLOW");
        $model->css = array ("/style/lfe.".VERSION.".css");
        return $model;
        }

    public function renderStyleSheets($request, $response, $args)
        {
        $files = glob(__DIR__."/../css/*.css");
        $version = $args['css'];
        $parts = preg_split ('#\.#', VERSION);
        $lastModifiedServer = array_pop ($parts);
        $lastModifiedClient = $request->getHeaderLine ('If-Modified-Since');

        // http://stackoverflow.com/questions/1583740/304-not-modified-and-front-end-caching
        $response = $response->withHeader('Cache-Control', 'max-age=604800')
            ->withHeader('Last-Modified', gmdate("D, d M Y H:i:s", $lastModifiedServer)." GMT")
            ->withHeader('Etag', $lastModifiedServer);

        if (!empty($lastModifiedClient) && strtotime ($lastModifiedClient) == $lastModifiedServer)
            {
            return $response->withStatus (304);
            }

        $response = $response->withHeader('Content-Type', 'text/css');
        foreach ($files as $file)
            $response = $response->write(file_get_contents ($file));
        return $response;
        }

    public function renderPage($request, $response, $args)
        {
        $name = $args['name'];
        $lng = $args['lng'];
        if ($this->logger) $this->logger->debug("UIHandler::renderPage $name/$lng");
//var_dump (VERSION);

        /*
        if (false == Authentication::getCurrentUserId())
            {
                var_dump ("No access");
            $app->render (401, array('error' => true, 'status' => 401, 'msg' => "No access"));
            }
        */

        $tpl = strtolower ($name);
        if (!is_file (TEMPLATES_DIR."$tpl.phtml"))
            {
            if ($this->logger) $this->logger->error("There is no ".TEMPLATES_DIR."$tpl.phtml file to render the page");
            if (!is_file (TEMPLATES_DIR."error.phtml"))
                {
                if ($this->logger) $this->logger->emergency("There is no ".TEMPLATES_DIR."error.phtml file to render the error");
                $response->withStatus(404)->withHeader('Content-Type', 'text/html')->write('Page not found');
                }
            else
                {
                $response = $this->app->getContainer()->view->render($response, "error.phtml", ["lng" => $lng, "model" => $this->getModel($name), "error" => "Page not found"]);
                $response->withStatus (404)->withHeader('Content-Type', 'text/html');
                }
            }
        else
            {
            $response->withHeader('Content-Type', 'text/html');
            $response = $this->app->getContainer()->view->render($response, "{$name}.phtml", ["lng" => $lng]);
            }

        return $response;
        }

    }