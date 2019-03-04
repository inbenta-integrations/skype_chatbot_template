### OBJECTIVE
This template has been implemented in order to develop Skype bots that consume from the new Inbenta Chatbot API with the minimum configuration and effort. The main class of this template is `SkypeConnector`. This class extends from a parent class named `ChatbotConnector` that can be used to develop bots for other external services like Facebook, Slack, WhatsApp, etc...

This template has been implemented to be used until Product chapter provide an official solution to connect our bots to external messaging services.

### FUNCTIONALITIES
This bot template inherits the functionalities from the `ChatbotConnector` library. Currently the features provided by that application are:

* Simple answers
* Multiple options
* Polar questions
* Chained answers
* Content ratings (yes/no + comment)
* Escalate to HyperChat after a number of no-results answers
* Escalate to HyperChat when matching with an 'Escalation FAQ'
* Send information to webhook through forms
* Custom FAQ title in button when displaying multiple options
* Retrieve Skype tokens from ExtraInfo
* Send a button that opens a configured URL along with the answer
* Retrieve translation labels from Extrainfo

### INSTALLATION
It's pretty simple to get this UI working. The mandatory configuration files are included by default in `/conf/custom` to be filled in, so you have to provide the information required in these files:

* **File 'api.php'**
    Provide the API Key and API Secret of your Chatbot Instance.

* **File 'environments.php'**
    Here you can define regexes to detect `development` and `preproduction` environments. If the regexes do not match the current conditions or there isn't any regex configured, `production` environment will be assumed.

Also, this template needs the Skype App Id and Password: `app_id` and `app_password`. This information is retrieved from ExtraInfo. The object you need is named `skype` and has two childs named `app_id` and `app_password`. Here are the steps to create the full `skype` object:

* Go to **Knowledge -> Extra Info -> Manage groups and types** and click on **Add new group**. Name it `skype`.
* Go to **Manage groups and types -> skype -> Add type**. Name it `app_id`, add a new property named `value` with type `text` and save.
* Go to **Manage groups and types -> skype -> Add type**. Name it `app_password`.
* Add three properties named `development`, `preproduction` and `production` with type `text` and save.

Now, create the ExtraInfo objects by clicking the **New entry** button. You can select these two new types: `app_id` and `app_password`. Create the two objects naming them as `app_id` and `app_password` respectively. Then, fill the information and remember to publish ExtraInfo by clicking the **Post** button.

**OPTIONAL**
Manage the translation labels from ExtraInfo. Here are the steps to create the translations object in ExtraInfo:
* Go to **Manage groups and types -> skype -> Add type**. Name it `translations` and add a new property with type `Multiple` named with your Chatbot's language label (en, es, it...).
* Inside your language, add all the labels that you want to override. Each label should be a `Text`entry (you can find the labels list below).
* Save your translations object.

Now you can create the ExtraInfo object by clicking the **New entry** button, selecting the `translations` type and naming it as `translations`. Then, fill each label with your desired translation and remember to publish ExtraInfo by clicking the **Post** button.

Here you have the current labels with their English value:
* agent_joined => 'Agent $agentName has joined the conversation.',
* api_timeout => 'Please, reformulate your question.',
* ask_rating_comment => 'Please tell us why',
* ask_to_escalate => 'Do you want to start a chat with a human agent?',
* chat_closed => 'Chat closed',
* creating_chat => 'I will try to connect you with an agent. Please wait.',
* error_creating_chat => 'There was an error joining the chat',
* escalation_rejected => 'What else can I do for you?',
* no => 'No',
* no_agents => 'No agents available',
* rate_content_intro => 'Was this answer helpful?',
* thanks => 'Thanks!',
* yes => 'Yes',


### HOW TO CUSTOMIZE
**From configuration**

For a default behaviour, the only requisite is to fill the basic configuration (more information on `/conf/README.md`). There are some extra configuration parameters in the configuration files that allow you to modify the basic-behaviour.


**Custom Behaviours**

If you need to customize the bot flow you need to extend the class `SkypeConnector` including it inside `/lib` folder (does not exists by default). This class must extend the default SkypeConnector, then you can override all the parent methods.

For example, when the bot is configured to escalate with an agent starts a conversation in HyperChat. If your bot needs to use an external chat service, you should override the parent method `escalateToAgent` and setup the external service:
```php
	//Tries to start a chat with an agent with an external service
	protected function escalateToAgent(){
		$useExternalService = $this->conf->get('chat.useExternal'),

		if( $useExternalService ){
		    //Inform the user that the chat is being created
			$this->sendMessagesToExternal( $this->buildTextMessage( $this->lang->translate('creating_chat') ) );

		    //Create a new instance for the external client
		    $externalChat = New SomeExternalChatClass( $this->conf->get('chat.externalConf') );
			$externalChat->openChat();
		}else{
			//Use the parent method to escalate to HyperChat
			parent::escalateToAgent();
		}
	}
```


**HyperChat escalation by no-result answer**

If your bot needs integration with HyperChat, fill the chat configuration at `/conf/conf-path/chat.php` and subscribe to the following events on your Backstage instance: `invitations:new`, `invitations:accept`, `forever:alone`, `chats:close`, `messages:new`. When subscribing the events in Backstage you've to point to the `/server.php` file in order to handle the events from HyperChat.

Configuration parameter `triesBeforeEscalation` sets the number of no-results answers after the bot should escalate to an agent.


**Escalation with FAQ**

If your bot has to escalate to HyperChat when matching an specific FAQ, the content needs to follow a few requisites:
- Dynamic setting named `ESCALATE`, non indexable, visible, `Text` box-type with `Allow multiple objects` option checked
- In the content, add a new object to the `Escalate` setting (with the plus sign near the setting name) and type the text `TRUE`.

After a Restart Project Edit and Sync & Restart Project Live your bot should escalate when this FAQ is matched
Note that the `server.php` file has to be suscribed to the required HyperChat events as described at the previous section.


### DEPENDENCIES
This application imports `inbenta/chatbot-api-connector` as a Composer dependency, that includes `symfony/http-foundation@^3.1` and `guzzlehttp/guzzle@~6.0` as dependencies too.

