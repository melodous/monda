<?php

namespace App\Presenters;

use Nette;
use Tracy\ILogger;


class ErrorPresenter extends DefaultPresenter implements Nette\Application\IPresenter
{
	/** @var ILogger */
	private $logger;


	public function __construct(ILogger $logger)
	{
		$this->logger = $logger;
	}


	/**
	 * @return Nette\Application\IResponse
	 */
	public function run(Nette\Application\Request $request)
	{
		$e = $request->getParameter('exception');

		if ($e instanceof Nette\Application\BadRequestException) {
			// $this->logger->log("HTTP code {$e->getCode()}: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}", 'access');
			return new Nette\Application\Responses\ForwardResponse($request->setPresenterName('Error4xx'));
		}

		$this->logger->log($e, ILogger::EXCEPTION);
                //debug_print_backtrace();
		BasePresenter::mexit($e->getCode(),$e->getMessage()."\n");
	}
}

class Error4xxPresenter extends ErrorPresenter {
    public function run(Nette\Application\Request $request) {
        DefaultPresenter::Help();
    }

}