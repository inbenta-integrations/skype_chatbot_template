<?php
namespace Inbenta\SkypeConnector\HyperChatAPI;

use Inbenta\SkypeConnector\ExternalAPI\SkypeAPIClient;
use Inbenta\ChatbotConnector\HyperChatAPI\HyperChatClient;

class SkypeHyperChatClient extends HyperChatClient
{

    // Instances an external client
    protected function instanceExternalClient($externalId, $appConf)
    {
        $request = file_get_contents('php://input');
        $creator = self::getCreatorFromEvent($appConf->get('chat.chat'), json_decode($request, true));

        if (isset($creator->extraInfo) && isset($creator->extraInfo->skypeData)) {
            // Instance new Skype API Client
            $externalClient = new SkypeAPIClient($appConf->get('skype.app_id'), $appConf->get('skype.app_password'), $request);
            $externalClient->setActivityPropertiesFromHyperChat(json_decode($creator->extraInfo->skypeData, true));
            return $externalClient;
        }
        return null;
    }

    public static function buildExternalIdFromRequest ($config)
    {
        $request = json_decode(file_get_contents('php://input'), true);

        $externalId = null;
        if (isset($request['trigger'])) {
            //Obtain user external id from the chat event
            $externalId = self::getExternalIdFromEvent($config, $request);
        }
        return $externalId;
    }

}
