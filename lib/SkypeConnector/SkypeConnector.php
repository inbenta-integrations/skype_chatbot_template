<?php
namespace Inbenta\SkypeConnector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\SkypeConnector\ExternalAPI\SkypeAPIClient;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\SkypeConnector\ExternalDigester\SkypeDigester;
use Inbenta\SkypeConnector\HyperChatAPI\SkypeHyperChatClient;

class SkypeConnector extends ChatbotConnector
{

	public function __construct($appPath)
	{
		try {
			parent::__construct($appPath);

			//Store request
			$request = file_get_contents('php://input');
			$conversationConf = array('configuration' => $this->conf->get('conversation.default'), 'userType' => $this->conf->get('conversation.user_type'), 'environment' => $this->environment);

			//Initialize and configure specific components for Skype
			$this->session 		= new SessionManager($this->getExternalIdFromRequest()); 												// Initialize session manager with user id
			$this->botClient 	= new ChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);

			// Retrieve tokens from ExtraInfo, if needed, and update configuration
			$this->getSkypeCredentialsFromExtraInfo();

			// Try to get the translations from ExtraInfo and update the language manager
			$this->getTranslationsFromExtraInfo('skype', 'translations');

			//Initialize Hyperchat events handler
			if ($this->conf->get('chat.chat.enabled')) {
				$chatEventsHandler = new SkypeHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $this->externalClient);
				$chatEventsHandler->handleChatEvent();
			}

			//Init application components
			$externalClient 		= new SkypeAPIClient($this->conf->get('skype.app_id'), $this->conf->get('skype.app_password'), $request);				//Instance Skype client
			$chatClient 			= new SkypeHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $externalClient);	//Instance HyperchatClient for Skype
			$externalDigester 		= new SkypeDigester($this->lang, $this->conf->get('conversation.digester'));											//Instance Skype digester

			$this->initComponents($externalClient, $chatClient, $externalDigester);
		}
		catch (Exception $e) {
			echo json_encode(["error" => $e->getMessage()]);
			die();
		}
	}

	/**
	 *	Retrieve Skype App Id and Pwd from ExtraInfo
	 */
	protected function getSkypeCredentialsFromExtraInfo()
	{
		$credentials = [];

		$extraInfo = $this->botClient->getExtraInfo('skype');
		foreach ($extraInfo->results as $element) {
			$value = isset($element->value->value) ? $element->value->value : $element->value;
			$credentials[$element->name] = $value;

		}
		
		// Store tokens in conf
		$environment = $this->environment;
		$this->conf->set('skype.app_id', $credentials['app_id']);
		$this->conf->set('skype.app_password', $credentials['app_password']->$environment);
	}

	/**
	 *	Return external id from request
	 */
	protected function getExternalIdFromRequest()
	{
		// Try to get user_id from a Skype message request
		$externalId = SkypeAPIClient::buildSessionIdFromRequest();
		if (is_null($externalId)) {
			// Try to get user_id from a Hyperchat event request
			$externalId = SkypeHyperChatClient::buildExternalIdFromRequest($this->conf->get('chat.chat'));
		}
		if (empty($externalId)) {
			$api_key = $this->conf->get('api.key');
			if (isset($_GET['hub_verify_token'])) {
				// Create a temporary session_id from a Facebook webhook linking request
				$externalId = "skype-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
			} elseif (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
				// Create a temporary session_id from a HyperChat webhook linking request
				$externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
			} else {
				throw new Exception("Invalid request");
				die();
			}
		}
		return $externalId;
	}

	// Override parent function to add the conversationDetails to user extraInfo
	//Tries to start a chat with an agent
	protected function escalateToAgent()
	{
		$agentsAvailable = $this->chatClient->checkAgentsAvailable();

		if ($agentsAvailable) {
			// Send 'creating_chat' message to user
			$this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('creating_chat')));
			// Build user data for HyperChat API
			$chatData = array(
				'roomId' => $this->conf->get('chat.chat.roomId'),
				'user' => array(
					'name' 			=> $this->externalClient->getFullName(),
					'contact' 		=> $this->externalClient->getEmail(),
					'externalId' 	=> $this->externalClient->getExternalId(),
					'extraInfo' 	=> array(
						"skypeData" => $this->externalClient->getMessageData()
					)
				)
			);
			$response =  $this->chatClient->openChat($chatData);
			if (!isset($response->error) && isset($response->chat)) {
				// Store in session that chat started
				$this->session->set('chatOnGoing', $response->chat->id);
			} else {
				// Send 'error_creating_chat' message to user
				$this->sendMessagesToExternal( $this->buildTextMessage( $this->lang->translate('error_creating_chat') ) );
			}
		} else {
			// Send 'no-agents-available' message if the escalation type is not no-results
			if ($this->session->get('escalationType') != static::ESCALATION_NO_RESULTS) {
				$this->sendMessagesToExternal($this->buildTextMessage( $this->lang->translate('no_agents')));
			}
		}
	}

}