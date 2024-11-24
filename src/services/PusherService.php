<?php

require_once __DIR__ . '/../../vendor/autoload.php';

class PusherService {
    private $pusher;
    
    public function __construct() {
        $appKey = getenv('PUSHER_APP_KEY');
        $appSecret = getenv('PUSHER_APP_SECRET');
        $appId = getenv('PUSHER_APP_ID');
        $cluster = getenv('PUSHER_CLUSTER');
        
        error_log("Initializing Pusher with: " . json_encode([
            'app_key' => $appKey,
            'app_id' => $appId,
            'cluster' => $cluster
        ]));
        
        $this->pusher = new Pusher\Pusher(
            $appKey,
            $appSecret,
            $appId,
            [
                'cluster' => $cluster,
                'useTLS' => true
            ]
        );
    }

    public function trigger($channel, $event, $data) {
        error_log("Triggering Pusher - Channel: {$channel}, Event: {$event}, Data: " . json_encode($data));
        $result = $this->pusher->trigger($channel, $event, $data);
        error_log("Pusher trigger result: " . json_encode($result));
        return $result;
    }
} 