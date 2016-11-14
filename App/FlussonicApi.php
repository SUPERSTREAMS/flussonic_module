<?php namespace App;
use WebSocket as WebSocket;


/**
 * FlussonicApi
 *
 * @uses RestAPI
 * @package
 * @author Iptvmatrix.net dev team
 * @version 1.0
 */
class FlussonicApi extends RestAPI
{
    protected $config = [];

    public function __construct($config)
    {
        parent::__construct();
        $config = (object) $config;
        $this->config = $config;
        $this->host($config->flussonic_host)
            ->authBasic($config->flussonic_user, $config->flussonic_pwd);
    }

    /**
     * apiGet
     *
     * return json decoded answer from GET request
     *
     * @param string $action
     * @return array
     */
    public function apiGet($action)
    {
        return json_decode($this->action($action)->execGet(), true);
    }

    /**
     * getServerInfo
     *
     * @return array
     */
    public function getServerInfo()
    {
        return $this->apiGet('/flussonic/api/server');
    }


    /**
     * getFeedInfo
     *
     * @param string $feed_name
     * @return array
     */
    public function getFeedInfo($feed_name)
    {
        $retVal = $this->apiGet("/flussonic/api/media_info/$feed_name");

        if (!$retVal) {
            return Result::failure("Error code: ".$this->getCode());
        }

        return $retVal;
    }

    /**
     * getMemUsage
     *
     * @return int
     */
    public function getMemUsage()
    {
        $c = $this->getWSConnection();
        $c->send("pulse_subscribe:total_memusage");

        $answer = json_decode($c->receive(), true);
        unset($c);

        return end(end($answer['total_memusage']['minute'])['data'])[1];
    }

    /**
     * getCpuUsage
     *
     * @return int
     */
    public function getCpuUsage()
    {
        $c = $this->getWSConnection();
        $c->send("pulse_subscribe:cpu_usage");

        $answer = json_decode($c->receive(), true);
        unset($c);

        return end(end($answer['cpu_usage']['minute'])['data'])[1];
    }

    /**
     * getNetworkStatus
     *
     * @return array
     */
    public function getNetworkStatus()
    {
        $retArr = ['in' => 0, 'out' => 0];

        $c = $this->getWSConnection();
        $c->send("pulse_subscribe:overview");

        $answer = json_decode($c->receive(), true);

        foreach ($answer['overview']['minute'] as $report)
        {
            if ($report['label'] == 'total_output') {
                $retArr['out'] = ceil(end($report['data'])[1] / 1000);
            }
            else if ($report['label'] == 'total_input') {
                $retArr['in'] = ceil(end($report['data'])[1] / 1000);
            }
        }

        unset($c);

        return $retArr;
    }

    protected function getWSConnection()
    {
        $user = $this->config->flussonic_user;
        $pwd  = $this->config->flussonic_pwd;
        $host = str_replace("http://", '', $this->config->flussonic_host);

        $url = "ws://$user:$pwd@$host/flussonic/api/events";

        return new WebSocket\Client($url);
    }

    /**
     * getStreams
     *
     * Returns all streams data from flussonic WebSocket API
     *
     * @return array
     */
    public function getStreams()
    {
        $c = $this->getWSConnection();
        $c->send("streams");

        $answer = json_decode($c->receive(), true);
        $this->streams = $answer['streams'];
        unset($c);

        return $this->streams;
    }

    /**
     * getSessions
     *
     * @return array
     */
    public function getSessions()
    {
        $this->sessions = $this->apiGet('/flussonic/api/sessions')['sessions'];
        return $this->sessions;
    }

    /**
     * getFeeds
     *
     * returns flussonic streams, filtered by application name
     *
     * @param string $app_name
     * @return array
     */
    public function getFeeds($app_name, $only_names = false)
    {
        $retArr = [];

        if (!$this->streams) {
            $this->getStreams();
        }

        foreach ($this->streams as $stream)
        {
            if (explode("/", $stream['name'])[0] == $app_name) {
                $retArr[] = $only_names ? $stream['name'] : $stream;
            }
        }

        return $retArr;
    }

    /**
     * getClients
     *
     * returns flussonic sessions, filtered by application name
     *
     * @param string $app_name
     * @return array
     */
    public function getClients($app_name)
    {
        $retArr = [];

        if (!$this->sessions) {
            $this->getSessions();
        }

        foreach ($this->sessions as $session)
        {
            if (explode("/", $session['name'])[0] == $app_name) {
                $retArr[] = $session;
            }
        }

        return $retArr;
    }


    /**
     * getDvrEdgeReplicatedStreams
     *
     * @param String $app_name
     * @return array
     */
    public function getDvrEdgeReplicatedStreams($app_name)
    {
        if ($edges = $this->getDvrEdges([]))
        {
            foreach($edges as $edge) {
                if ($edge['meta']['comment'] == $app_name) {
                    return $edge['only'];
                }
            }
        }

        return [];
    }

    /**
     * getDvrEdgeSessions
     *
     * @param array $origin_streams
     * @return array
     */
    public function getDvrEdgeSessions(array $origin_streams = [])
    {
        $retArr = [];

        if (!$this->sessions) {
            $this->getSessions();
        }

        foreach ($this->sessions as $session)
        {
            if (in_array(explode('/', $session['name'])[0], $origin_streams)) {
                $retArr[] = $session;
            }
        }

        return $retArr;

    }

    /**
     * getDvrEdgeStreams
     *
     * @param array $origin_streams
     * @return array
     */
    public function getDvrEdgeStreams(array $origin_streams = [])
    {
        $retArr = [];

        if (!$this->streams) {
            $this->getStreams();
        }

        foreach ($this->streams as $stream)
        {
            if (in_array($stream['name'], $origin_streams)) {
                $retArr[] = $stream;
            }
        }

        return $retArr;
    }

    /**
     * createFeed
     *
     * @param string $name
     * @param string $url
     * @param bool $persistent
     * @return mixed
     */
    public function createFeed($name, $url, $persistent = true)
    {
        $streamtype = $persistent ? "stream" : "ondemand";
        $postfield = "$streamtype $name $url";
        $this->action("/flussonic/api/config/stream_create");

        return $this->execBinaryPost($postfield);
    }

    /**
     * removeAllStreams
     *
     * Delete all streams for Matrix Application
     *
     * @param string $app_name
     * @return this
     */
    public function removeAllStreams($app_name)
    {
        foreach($this->getFeeds($app_name) as $feed)
        {
            $this->deleteStream($feed['name']);
        }

        return $this;
    }

    /**
     * deleteStream
     *
     * @param string $name
     * @return this
     */
    public function deleteStream($name)
    {
        $this->action("/flussonic/api/config/stream_delete");
        return $this->execBinaryPost($name);
    }

    public function deleteSource($source_id)
    {
        $source_id = (int) $source_id;
        $this->action("/flussonic/api/modify_config");
        $data = '{"sources":{"'. $source_id . '": null}}';

        return $this->execBinaryPost($data);
    }

    /**
     * restartStream
     *
     * @param string $name
     * @return mixed
     */
    public function restartStream($name)
    {
        $this->action("/flussonic/api/stream_restart/$name");
        return $this->execPost();
    }

    /**
     * removeDevices
     *
     * Drop devices via matrix tokens
     *
     * @param tokens $tokens
     * @return mixed
     */
    public function removeDevices($tokens)
    {
        $toRemove = '';
        foreach ($this->sessions as $flussonic_session) {
            if (empty($flussonic_session['token'])) {
                continue;
            }

            if (in_array($flussonic_session['token'], $tokens)) {
                $toRemove .= $toRemove ? "\n" : "";
                $toRemove .= $flussonic_session['id'];
            }
        }

        $this->action("/flussonic/api/close_sessions");
        return $this->execBinaryPost($toRemove);
    }

    /**
     * hashToToken
     *
     * Matrix hash billing_id.portal_id.user_id.platform_id.device_id to Token
     *
     * @param string $hash
     * @param string $app_name
     * @return string
     */
    public function hashToToken($hash, $app_name, $type)
    {
        $hash_pieces = explode('.', $hash);

        $hash_billing_id  = $hash_pieces[0];
        $hash_portal_id   = $hash_pieces[1];
        $hash_user_id     = $hash_pieces[2];
        $hash_device_id   = $hash_pieces[4];
        $hash_platform_id = $hash_pieces[3];

        switch ($type) {
            case Core::TYPE_DVR_EDGE:
                $only    = $this->getDvrEdgeReplicatedStreams($app_name);
                $clients = $this->getDvrEdgeSessions($only);
                break;

            default:
                $clients = $this->getClients($app_name);
                break;
        }

        foreach ($clients as $session)
        {
            if (empty($session['token'])) {
                continue;
            }

            $token_pieces = explode('.', $session['token']);
            $token_billing_id  = $token_pieces[0];
            $token_portal_id   = $token_pieces[1];
            $token_user_id     = $token_pieces[2];
            $token_device_id   = $token_pieces[3];
            $token_platform_id = $token_pieces[6];

            if ($hash_billing_id  == $token_billing_id &&
                $hash_portal_id   == $token_portal_id  &&
                $hash_user_id     == $token_user_id    &&
                $hash_device_id   == $token_device_id  &&
                $hash_platform_id == $token_platform_id
            ) {
                return $session['token'];
            }
        }

        return '';
    }

    public function setServerClusterKey($key)
    {
        $this->action("/flussonic/api/modify_config");

        return $this->execBinaryPost(json_encode(["cluster_key" => $key]));
    }

    public function updateDvrChannel($name, array $sources, $storage, $depth)
    {
        $urls = [];
        foreach ($sources as $src) {
            $urls[] = ["url" => $src];
        }

        $cfg_arr = json_encode([
            "streams" =>
            [
                $name => [
                    "name" => $name,
                    "urls" => $urls,
                    "dvr"  => [
                        "root" => $storage,
                        "dvr_limit" => $depth
                    ]
                ]
            ]
        ]);

        $this->action("/flussonic/api/modify_config");

        return $this->execBinaryPost($cfg_arr);
    }

    public function getDvrEdges($app_name)
    {
        $json_cfg = $this->action("/flussonic/api/read_config")
            ->execGet();
        $flussonic_cfg = json_decode($json_cfg, true);

        return isset($flussonic_cfg['sources']) ? $flussonic_cfg['sources'] : [];
    }

    public function updateDvrEdge($index, $app_name, $data)
    {
        $cfg_arr = json_encode([
            'sources' => [
                $index => [
                    "position" => (int) $index,
                    "urls" => $data['hosts'],
                    "only" => $data['only'],
                    "cluster_key" => $data['cluster_key'],
                    "meta" => ["comment" => $app_name],
                    "cache" => [
                        "path" => $data['folder'],
                        "time_limit" => $data['cache'] * 60 * 60 * 24 //Days to sec
                    ],
                ]
            ]
        ]);

        $this->action("/flussonic/api/modify_config");

        return $this->execBinaryPost($cfg_arr);

    }

    public function setGlobalAuth($url)
    {
        $this->action("/flussonic/api/modify_config");

        return $this->execBinaryPost(json_encode(["auth" => ["url" => $url]]));
    }

    public function readConfig()
    {
        $json_cfg = $this->action("/flussonic/api/read_config")
            ->execGet();

        return json_decode($json_cfg, true);
    }

    public function getAllSourcesFromConfig(array $cfg)
    {
        return isset($cfg['sources']) ? $cfg['sources'] : [];
    }

    public function getAllStreamsFromCOnfig(array $cfg)
    {
        return isset($cfg['streams']) ? $cfg['streams'] : [];
    }
}