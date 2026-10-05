<?php

namespace Tests\Unit;

use App\Libraries\InboxRealtimeWs;
use PHPUnit\Framework\TestCase;

final class InboxRealtimeWsTest extends TestCase
{
    public function testSecurePageUsesSameOriginWssProxyPath(): void
    {
        $this->assertSame(
            'wss://192.168.1.10/realtime-ws',
            InboxRealtimeWs::url(true, '192.168.1.10', 'http://AULIA3:3000')
        );
        $this->assertSame(
            'wss://pos.local:8443/realtime-ws',
            InboxRealtimeWs::url(true, 'pos.local:8443', 'http://AULIA3:3000')
        );
    }

    public function testInsecurePageKeepsDirectGatewayWebSocket(): void
    {
        $this->assertSame(
            'ws://AULIA3:3000/realtime',
            InboxRealtimeWs::url(false, '192.168.1.10', 'http://AULIA3:3000')
        );
    }

    public function testInsecurePageHandlesHttpsGatewayAndTrailingSlash(): void
    {
        $this->assertSame(
            'wss://gw.example.com:9443/realtime',
            InboxRealtimeWs::url(false, '192.168.1.10', 'https://gw.example.com:9443/')
        );
        $this->assertSame(
            'ws://AULIA3/realtime',
            InboxRealtimeWs::url(false, '192.168.1.10', 'http://AULIA3')
        );
    }

    public function testSecurePageWithEmptyHostFallsBackToGateway(): void
    {
        $this->assertSame(
            'ws://AULIA3:3000/realtime',
            InboxRealtimeWs::url(true, '', 'http://AULIA3:3000')
        );
    }
}
