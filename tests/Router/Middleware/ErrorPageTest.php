<?php

use Jasny\Router;
use Jasny\Router\Routes\Glob;
use Jasny\Router\Middleware\ErrorPage;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class ErrorPageTest extends PHPUnit_Framework_TestCase
{
    /**
     * Test invoke with invalid 'next' param
     */
    public function testInvokeInvalidNext()
    {
        $middleware = new ErrorPage($this->getRouter());
        list($request, $response) = $this->getRequests();

        $this->expectException(\InvalidArgumentException::class);

        $result = $middleware($request, $response, 'not_callable');
    }

    /**
     * Test that 'next' callback is invoked when route is found, and not called in case of error
     *
     * @dataProvider invokeProvider
     * @param int $statusCode
     */
    public function testInvoke($statusCode)
    {
        $isError = $statusCode >= 400;
        $router = $this->getRouter();
        $middleware = new ErrorPage($router);
        list($request, $response) = $this->getRequests($statusCode);

        if ($isError) {
            $this->expectSetErrorRequest($router, $request, $response, $statusCode);
        }

        $result = $middleware($request, $response, function($request, $response) {
            $response->nextCalled = true;

            return $response;
        });

        $this->assertEquals(get_class($response), get_class($result));

        $isError ?
            $this->assertTrue(!isset($result->nextCalled)) :
            $this->assertTrue($result->nextCalled);
        
    }

    /**
     * Provide data for testing '__invoke' method
     *
     * @return array
     */
    public function invokeProvider()
    {
        return [
            [200],
            [300],
            [400],
            [404],
            [500],
            [503]
        ];
    }

    /**
     * Get requests for testing
     *
     * @param int $statusCode 
     * @return array
     */
    public function getRequests($statusCode = null)
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        if ($statusCode) {
            $response->method('getStatusCode')->will($this->returnValue($statusCode));            
        }

        return [$request, $response];
    }

    /**
     * @return Router
     */
    public function getRouter()
    {
        return $this->getMockBuilder(Router::class)->disableOriginalConstructor()->getMock();
    }

    /**
     * Expect for error
     *
     * @param Router $router
     * @param ServerRequestInterface $request 
     * @param ResponseInterface $response 
     * @param int $statusCode 
     */
    public function expectSetErrorRequest($router, $request, $response, $statusCode)
    {
        $uri = $this->createMock(UriInterface::class);

        $uri->expects($this->once())->method('withPath')->with($this->equalTo("/$statusCode"))->will($this->returnSelf());
        $request->expects($this->once())->method('getUri')->will($this->returnValue($uri));
        $request->expects($this->once())->method('withUri')->with($this->equalTo($uri), $this->equalTo(true))->will($this->returnSelf());
        $router->expects($this->once())->method('run')->with($this->equalTo($request), $this->equalTo($response))->will($this->returnValue($response));
    }
}
