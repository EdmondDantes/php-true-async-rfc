<?php

function startChatServer(string $host, int $port): void
{
    async $serverScope {
        // Store connected clients
        $clients = [];
        
        // Create server socket
        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($server, $host, $port);
        socket_listen($server, 10);
        
        echo "Chat server started on $host:$port\n";
        
        // Accept new connections
        spawn use($server, &$clients) {
            while (($client = socket_accept($server)) !== false) {
                
                $id = uniqid('client_');
                $clients[$id] = $client;
                
                // Handle each client in a separate coroutine and child scope
                spawn with Scope::inherit() use($client, $id, &$clients) {
                    try {
                        // Send welcome message
                        socket_write($client, "Welcome to the chat room!\n");
                        
                        // Read messages from this client and broadcast them
                        while (true) {
                            $message = socket_read($client, 1024);
                            
                            if ($message === false || $message === '') {
                                break; // Client disconnected
                            }
                            
                            $broadcastMsg = "Client $id: $message";
                            echo $broadcastMsg;
                            
                            // Broadcast to all other clients
                            foreach ($clients as $cid => $c) {
                                if ($cid !== $id) {
                                    spawn socket_write($c, $broadcastMsg);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        echo "Error handling client $id: " . $e->getMessage() . "\n";
                    } finally {
                        // Clean up when client disconnects
                        socket_close($client);
                        unset($clients[$id]);
                        echo "Client $id disconnected\n";
                    }
                };
            }
        };
        
        // Wait for Ctrl+C
        try {
            await spawn Async\signal(SIGINT);
        } finally {
            $serverScope->cancel(new Async\CancellationException("Server shutting down"));
            
            echo "Shutting down server...\n";
            
            try {
                await $serverScope->allTasks();
            } finally {
                // Close all client sockets
                if(!empty($clients)) {
                    user_error('Closing remaining clients', E_USER_WARNING);
                }
                
                foreach ($clients as $client) {
                    socket_close($client);
                }
                
                socket_close($server);
            }
        }
    }
}

// Start the server
startChatServer('127.0.0.1', 8080);