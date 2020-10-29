<?php

namespace Traits;

use Blackfire\Client;
use Blackfire\Bridge\Guzzle\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Blackfire\Profile;

/**
 * Provides methods to support profiling of web tests.
 *
 * This trait is meant to be used only by test classes.
 */
trait BlackfireTestTrait {

  /**
   * Prepare the request client to profile all requests using Blackfire.
   */
  public function prepareClient() {
    $blackfire = new Client();
    $handlerStack = $this->getDriverInstance()
      ->getClient()
      ->getClient()
      ->getConfig('handler');
    $handlerStack
      ->push($this->getMiddleware(), 'blackfire-enabler');
    $handlerStack
      ->push(Middleware::create($blackfire), 'blackfire');
  }

  /**
   * Provides a Guzzle middleware handler to enable Blackfire for every request.
   *
   * @return callable
   *   The callable handler that will do the logging.
   */
  protected function getMiddleware() {
    return function (callable $handler) {
      $blackfire = new Client();
      return function (RequestInterface $request, array $options) use ($handler, $blackfire) {
        $options['blackfire'] = true;
        return $handler($request, $options)
          ->then(function (ResponseInterface $response) use ($request, $blackfire) {
            $profile = $blackfire->getProfile($response->getHeader('X-Blackfire-Profile-Uuid')[0]);
            fwrite(STDERR, "\n" . $request->getUri());
            $this->printProfile($profile);
            return $response;
          });
      };
    };
  }

  /**
   * Profile a specific request using Blackfire.
   *
   * @param string $name
   *   A human-meaningful name for this request.
   * @param callable $closure
   *   A closure whose execution will be profiled with Blackfire.
   *
   * @return \Blackfire\Profile
   *   A profile representing the request.
   */
  public function profileRequest(string $name, callable $closure) {
    $blackfire = new Client();
    $profileRequest = $blackfire->createRequest($name);
    $this->getDriverInstance()->getClient()->setHeader('X-Blackfire-Query', $profileRequest->getToken());
    $closure();
    $profile = $blackfire->getProfile($profileRequest->getUuid());
    $this->printProfile($profile);
    return $profile;
  }

  /**
   * Print out some useful information about this profile.
   *
   * @param \Blackfire\Profile $profile
   *   A profile representing the request.
   */
  public function printProfile(Profile $profile) {
    $caller = $this->getTestMethodCaller();
    fwrite(STDERR, "\nCalled from" . $caller['function'] . ' line ' . $caller['line']);
    $cost = $profile->getMainCost();
    fwrite(STDERR, "\nWall Time\t" . $cost->getWallTime());
    fwrite(STDERR, "\nCPU\t\t" . $cost->getCpu());
    fwrite(STDERR, "\nI/O\t\t" . $cost->getIo());
    fwrite(STDERR, "\nNetwork\t\t" . $cost->getNetwork());
    fwrite(STDERR, "\nPeak Memory\t" . $cost->getPeakMemoryUsage());
    fwrite(STDERR, "\nMemory\t\t" . $cost->getMemoryUsage());
    $url = $profile->getUrl();
    fwrite(STDERR, "\nResults available at $url \n");
  }

}
            