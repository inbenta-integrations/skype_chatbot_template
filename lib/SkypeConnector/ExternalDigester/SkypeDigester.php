<?php
namespace Inbenta\SkypeConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;

class SkypeDigester extends DigesterInterface
{

	protected $conf;
	protected $channel;
	protected $langManager;
	protected $externalMessageTypes = array(
		'text',
		'postback',
		'attachment',
	);

	public function __construct($langManager, $conf)
	{	
		$this->langManager = $langManager;
		$this->channel = 'Skype';
		$this->conf = $conf;
	}

	/**
	*	Returns the name of the channel
	*/
	public function getChannel()
	{
		return $this->channel;
	}
	
	/**
	**	Checks if a request belongs to the digester channel
	**/
	public static function checkRequest($request)
	{
		$request = json_decode($request);
		if (isset($request->type) && $request->type == "message" && isset($request->conversation) && isset($request->recipient)) {
			return true;
		}
		return false;
	}

	/**
	**	Formats a channel request into an Inbenta Chatbot API request
	**/
	public function digestToApi($request)
	{
		$request = json_decode($request);
		if (empty($request) || (!empty($request->type) && $request->type == 'conversationUpdate')) {
			return [];
		}

		$messages = array($request);
		$output = [];

		foreach ($messages as $msg) {
			$msgType = $this->checkExternalMessageType($msg);

			$digester = 'digestFromSkype' . ucfirst($msgType);
			
			//Check if there are more than one responses from one incoming message
			$digestedMessage = $this->$digester($msg);
			if (isset($digestedMessage['multiple_output'])) {
				foreach ($digestedMessage['multiple_output'] as $message) {
					$output[] = $message;
				}
			} else {
				$output[] = $digestedMessage;
			}
		}

		return $output;
	}

	/**
	**	Formats an Inbenta Chatbot API response into a channel request
	**/
	public function digestFromApi($request, $lastUserQuestion='')
	{
		//Parse request messages
		if (isset($request->answers) && is_array($request->answers)) {
			$messages = $request->answers;
		} elseif ($this->checkApiMessageType($request) !== null) {
			$messages = array('answers' => $request);
		} else {
			throw new Exception("Unkwnown ChatbotAPI response: " . json_encode($request, true));
		}

		$output = [];
		foreach ($messages as $msg) {
			$msgType = $this->checkApiMessageType($msg);
			$digester = 'digestFromApi' . ucfirst($msgType);
			$digestedMessage = $this->$digester($msg, $lastUserQuestion);

			//Check if there are more than one responses from one incoming message
			if (isset($digestedMessage['multiple_output'])) {
				foreach ($digestedMessage['multiple_output'] as $message) {
					$output[] = $message;
				}
			} else {
				$output[] = $digestedMessage;
			}
		}
		return $output;
	}

	/**
	**	Classifies the external message into one of the defined $externalMessageTypes
	**/
	protected function checkExternalMessageType($message)
	{
		foreach ($this->externalMessageTypes as $type) {
			$checker = 'isSkype' . ucfirst($type);
			if ($this->$checker($message)) {
				return $type;
			}
		}
	}

	/**
	**	Classifies the API message into one of the defined $apiMessageTypes
	**/
	protected function checkApiMessageType($message)
	{
		foreach ( $this->apiMessageTypes as $type ) {
			$checker = 'isApi' . ucfirst($type);
			if ($this->$checker($message)) {
				return $type;
			}
		}
		return null;
	}

	/********************** EXTERNAL MESSAGE TYPE CHECKERS **********************/
	
	protected function isSkypeText($message)
	{
		$isText = !empty($message->text);
		return $isText && !$this->isSkypePostback($message);
	}

	protected function isSkypePostback($message)
	{
        $explicitPostback = isset($message->text) && isset($message->channelData) && isset($message->channelData->postback) && $message->channelData->postback;
        $smbaPostback = isset($message->channelData) && isset($message->channelData->text) && strpos($message->channelData->text, "smbapostback") !== false;
        return $explicitPostback || $smbaPostback;
	}

	protected function isSkypeAttachment($message)
	{
		return isset($message->attachments) && count($message->attachments);
	}


	/********************** API MESSAGE TYPE CHECKERS **********************/
	
	protected function isApiAnswer($message)
	{
		return (isset($message->type) && $message->type == 'answer');
	}

	protected function isApiPolarQuestion($message)
	{
		return (isset($message->type) && $message->type == "polarQuestion");
	}

	protected function isApiMultipleChoiceQuestion($message)
	{
		return (isset($message->type) && $message->type == "multipleChoiceQuestion");
	}

	protected function isApiExtendedContentsAnswer($message)
	{
		return (isset($message->type) && $message->type == "extendedContentsAnswer");
	}

	protected function hasTextMessage($message)
	{
		return isset($message->message) && is_string($message->message);
	}


	/********************** Skype MESSAGE DIGESTERS **********************/

	protected function digestFromSkypeText($message)
	{
		return array(
			'message' => $message->text
		);
	}

	protected function digestFromSkypePostback($message)
	{
		return json_decode($message->text, true);
	}

	protected function digestFromSkypeAttachment($message)
	{
		$attachments = [];
		
		foreach ($message->message->attachments as $attachment) {
			$attachments[] = array('message' => $attachment->contentUrl);
		}

		return ["multiple_output" => $attachments];
	}

	/********************** CHATBOT API MESSAGE DIGESTERS **********************/

	protected function digestFromApiAnswer($message)
	{
		$output = array();
		$urlButtonSetting = isset($this->conf['url_buttons']['attribute_name']) ? $this->conf['url_buttons']['attribute_name'] : '';

		if (strpos($message->message, '<img')) {
			// Handle a message that contains an image (<img> tag)
			$output['multiple_output'] = $this->handleMessageWithImages($message);
		} elseif (isset($message->attributes->$urlButtonSetting) && !empty($message->attributes->$urlButtonSetting)) {
			// Send a button that opens an URL
			$output = $this->buildUrlButtonMessage($message, $message->attributes->$urlButtonSetting);
		} else {
			// Add simple text-answer
			$output = ['text' => strip_tags($message->message)];
		}
		return $output;
	}

	protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion)
	{
		$isMultiple = isset($message->flags) && in_array('multiple-options', $message->flags);
		$buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';
		$buttons = array();
		foreach ($message->options as $option) {
			$optionTitle = $isMultiple && isset($option->attributes->$buttonTitleSetting) ? $option->attributes->$buttonTitleSetting : $option->label;
			$buttons []= [
                "title" => $optionTitle,
                "type" 	=> "postBack",
                "value" => json_encode(["message" => $lastUserQuestion, "option" => $option->value])
            ];
		}
        return [
            "text" => strip_tags($message->message),
            "attachmentLayout" => "list",
            "attachments" => [
                [
                    "contentType" => "application/vnd.microsoft.card.hero",
                    "content" => [
                        "text" => "",
                        "buttons" => $buttons
                    ]
                ]
            ]
        ];
	}

	protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
	{
		$buttons = array();
		foreach ($message->options as $option) {
			$buttons []= [
                "title" => $this->langManager->translate($option->label),
                "type" 	=> "postBack",
                "value" => json_encode(["message" => $lastUserQuestion, "option" => $option->value])
            ];
		}

        return [
            "text" => strip_tags($message->message),
            "attachmentLayout" => "list",
            "attachments" => [
                [
                    "contentType" => "application/vnd.microsoft.card.hero",
                    "content" => [
                        "text" => "",
                        "buttons" => $buttons
                    ]
                ]
            ]
        ];
	}

	protected function digestFromApiExtendedContentsAnswer($message)
	{	
		$buttonTitleSetting = isset($this->conf['button_title']) ? $this->conf['button_title'] : '';
		$buttons = array();
		$message->subAnswers = array_slice($message->subAnswers, 0, 3);

		foreach ($message->subAnswers as $option) {
			$buttons []= [
                "title" => isset($option->attributes->buttonTitleSetting) ? $option->attributes->$buttonTitleSetting: $option->message,
                "type" 	=> "postBack",
                "value" => json_encode(['extendedContentAnswer' => $option])
            ];
		}
        return [
            "text" => strip_tags($message->message),
            "attachmentLayout" => "list",
            "attachments" => [
                [
                    "contentType" => "application/vnd.microsoft.card.hero",
                    "content" => [
                        "text" => "",
                        "buttons" => $buttons
                    ]
                ]
            ]
        ];
	}

	/********************** MISC **********************/

	public function buildContentRatingsMessage($ratingOptions, $rateCode)
	{
        $buttons = array();
        foreach ($ratingOptions as $option) {
            $buttons []= [
                "title" => $this->langManager->translate($option['label']),
                "type" 	=> "postBack",
                "value" => json_encode([
					'askRatingComment' => isset($option['comment']) && $option['comment'],
					'isNegativeRating' => isset($option['isNegative']) && $option['isNegative'],
					'ratingData' =>	[
						'type' => 'rate',
						'data' => array(
							'code' 	  => $rateCode,
							'value'   => $option['id'],
							'comment' => null
						)
					]
				], true)
            ];
		}
        return [
			"text" => strip_tags($this->langManager->translate('rate_content_intro')),
            "attachmentLayout" => "list",
            "attachments" => [
                [
                    "contentType" => "application/vnd.microsoft.card.hero",
                    "content" => [
                        "text" => "",
                        "buttons" => $buttons
                    ]
                ]
            ]
        ];
	}

	/**
	 *	Splits a message that contains an <img> tag into text/image/text and displays them in Skype
	 */
	protected function handleMessageWithImages($message)
	{
		//Remove \t \n \r and HTML tags (keeping <img> tags)
		$text = str_replace(["\r\n", "\r", "\n", "\t"], '', strip_tags($message->message, "<img>"));
		//Capture all IMG tags. Will capture image URL, image name and extension
		preg_match_all('/<\s*img\s*.*?src\s*=\s*?"(.+?\/?\??([^\/]*\.(\w{3,4})))".*?\s*?\/?>/', $text, $matches, PREG_SET_ORDER, 0);

		$output = array();
		foreach ($matches as $imgData) {
			// Get the position of the <img> tag to split answer in some messages
			$imgPosition = strpos($text, $imgData[0]);
			// Append first text-part of the message to the answer
			$output[] = ['text' => substr($text, 0, $imgPosition)];
			// Append the current image to the answer
			$output[] = [
				'attachments' => [
					[
						'contentType' 	=> 'image/' . $imgData[3],	// Image type: "image/jpg"
						'contentUrl' 	=> $imgData[1],				// Image URL
						'name'			=> $imgData[2]				// Image file name: "my_image.jpg"
					]
				]
			];
			// Remove the <img> part from the input string
			$position = $imgPosition + strlen($imgData[0]);
			$text = substr($text, $position);
		}

		// If there is any text after the last image, append to the answer
		if (strlen($text)) {
			$output[] = array('text' => $text);
		}
		return $output;
	}

    /**
     *	Sends the text answer and displays an URL button
     */
    protected function buildUrlButtonMessage($message, $urlButton)
    {
        $buttonTitleProp = $this->conf['url_buttons']['button_title_var'];
        $buttonURLProp = $this->conf['url_buttons']['button_url_var'];

        if (!is_array($urlButton)) {
            $urlButton = [ $urlButton ];
        }

        $buttons = array();
        foreach ($urlButton as $button) {
            // If any of the urlButtons has any invalid/missing url or title, abort and send a simple text message
            if (!isset($button->$buttonURLProp) || !isset($button->$buttonTitleProp) || empty($button->$buttonURLProp) || empty($button->$buttonTitleProp)) {
                return ['text' => strip_tags($message->message)];
            }
            $buttons [] = [
                "title" => $button->$buttonTitleProp,
                "type"  => "openUrl",
                "value" => $button->$buttonURLProp
            ];
        }

        return [
            "text" => strip_tags($message->message),
            "attachmentLayout" => "list",
            "attachments" => [
                [
                    "contentType" => "application/vnd.microsoft.card.hero",
                    "content" => [
                        "text" => "",
                        "buttons" => $buttons
                    ]
                ]
            ]
        ];
    }

    public function buildEscalationMessage()
    {
    	$buttons = array();
        $escalateOptions = [
            [
                "label" => 'yes',
                "escalate" => true
            ],
            [
                "label" => 'no',
                "escalate" => false
            ],
        ];
		foreach ($escalateOptions as $option) {
			$buttons []= [
                "title" => $this->langManager->translate($option['label']),
                "type" 	=> "postBack",
                "value" => json_encode(['escalateOption' => $option['escalate']], true)
            ];
		}
        return [
		    "text" => $this->langManager->translate('ask_to_escalate'),
            "attachmentLayout" => "list",
            "attachments" => [
                [
                    "contentType" => "application/vnd.microsoft.card.hero",
                    "content" => [
                        "text" => "",
                        "buttons" => $buttons
                    ]
                ]
            ]
        ];
    }

}
