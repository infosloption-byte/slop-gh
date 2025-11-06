<?php

use Pusher\Pusher;

class PusherService {
    private $pusher;
    private $log;

    public function __construct($log) {
        $this->log = $log;
        try {
            $this->pusher = new Pusher(
                $_ENV['PUSHER_APP_KEY'],
                $_ENV['PUSHER_APP_SECRET'],
                $_ENV['PUSHER_APP_ID'],
                ['cluster' => $_ENV['PUSHER_APP_CLUSTER'], 'useTLS' => true]
            );
        } catch (Exception $e) {
            $this->log->error('Failed to initialize Pusher Service.', ['error' => $e->getMessage()]);
            throw new Exception("Notification service is currently unavailable.");
        }
    }

    /**
     * Authorizes a user's subscription to a private channel using socket_auth.
     *
     * @param string $channel_name
     * @param string $socket_id
     * @return string The authorization string (JSON).
     */
    public function authorizeChannel($channel_name, $socket_id) {
        // The correct method name is socket_auth for private channels
        return $this->pusher->socket_auth($channel_name, $socket_id);
    }

    /**
     * Triggers an event on a user's channel.
     * Returns true on success and false on failure.
     * The $link parameter is NOT needed here, as this class only talks to Pusher.
     *
     * @param int $user_id
     * @param string $event_name
     * @param array $data
     * @return bool True if the trigger was successful, false otherwise.
     */
    public function sendToUser($user_id, $event_name, $data) {
        $channel = 'private-user-' . $user_id;
        try {
            $this->pusher->trigger($channel, $event_name, $data);
            return true; // Return true on success
        } catch (Exception $e) {
            $this->log->error('Pusher trigger failed.', [
                'user_id' => $user_id, 
                'event' => $event_name, 
                'error' => $e->getMessage()
            ]);
            return false; // Return false on failure
        }
    }
}