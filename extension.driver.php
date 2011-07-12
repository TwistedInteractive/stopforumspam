<?php

class extension_stopforumspam extends Extension
{

    public $username;
    public $email;
    public $hidden;
    public $timestamp;

    public function __construct(Array $args)
    {
        parent::__construct($args);

        // Load config file:
        include_once(EXTENSIONS.'/stopforumspam/config.php');
        $this->username = $username;
        $this->email = $email;
        $this->hidden = $hidden;
        $this->timestamp = $timestamp;

        
    }

    public function about()
    {
        return array('name' => 'Event Filter: Stop Forum Spam',
                     'version' => '1.3',
                     'release-date' => '2011-01-09',
                     'author' => array('name' => 'John Porter',
                                       'website' => 'http://designermonkey.co.uk',
                                       'email' => 'contact@designermonkey.co.uk'),
                     'description' => 'Allows you to add spam filters to your events.'
        );
    }

    public function getSubscribedDelegates()
    {
        return array(
            array(
                'page' => '/blueprints/events/new/',
                'delegate' => 'AppendEventFilter',
                'callback' => 'addFilterToEventEditor'
            ),
            array(
                'page' => '/blueprints/events/edit/',
                'delegate' => 'AppendEventFilter',
                'callback' => 'addFilterToEventEditor'
            ),
            array(
                'page' => '/blueprints/events/new/',
                'delegate' => 'AppendEventFilterDocumentation',
                'callback' => 'addFilterDocumentationToEvent'
            ),
            array(
                'page' => '/blueprints/events/edit/',
                'delegate' => 'AppendEventFilterDocumentation',
                'callback' => 'addFilterDocumentationToEvent'
            ),
            array(
                'page' => '/frontend/',
                'delegate' => 'EventPreSaveFilter',
                'callback' => 'processEventData'
            ),
        );
    }

    public function addFilterToEventEditor($context)
    {
        $context['options'][] = array('stopforumspam', @in_array('stopforumspam', $context['selected']), 'Stop Forum Spam: Check Details.');
    }

    public function addFilterDocumentationToEvent($context)
    {
        if (is_array($context['selected']) && !in_array('stopforumspam', $context['selected'])) return;

        $context['documentation'][] = new XMLElement('h3', '\'Stop Forum Spam\' service');

        $context['documentation'][] = new XMLElement('p', 'This event filter will check any user registration, or blog/forum comment with the <a href="http://www.stopforumspam.com">Stop Forum Spam</a> service to see if it has been registered as a spammer.');

        $context['documentation'][] = new XMLElement('p', 'The following is an example of the XML returned form this filter:');
        $code = '<filter type="stopforumspam" status="passed">username passed spam check</filter>
<filter type="stopforumspam" status="failed">email failed as spam</filter>
<filter type="stopforumspam" status="passed">ipaddress passed spam check</filter>';

        $context['documentation'][] = contentBlueprintsEvents::processDocumentationCode($code);

        $context['documentation'][] = new XMLElement('p', 'The filter will perform an ip address check, and check the posted fields for a username and email (neither field are required). If neither fields are available, the filter will return dependent on the ip address only. The more data provided, the more chance of catching spammers! Note that if one of the three checks fails, the whole event fails. All three are displayed so you can reference which one failed if you wish.');

        $context['documentation'][] = new XMLElement('p', 'The filter doesn\'t expect you to provide hidden fields specifically for this service, and will use your standard username and email inputs. If you want to set the names of the fields, change the values in the config.php-file, located in the folder of this extension. The IP is generated by the extension for you.');

        $context['documentation'][] = new XMLElement('p', 'Example fields used would be:');

        $code = '<input name="fields[username]" value="" type="text" />
<input name="fields[email]" value="" type="text" />
';
        $context['documentation'][] = contentBlueprintsEvents::processDocumentationCode($code);

        $context['documentation'][] = new XMLElement('p', 'You can also use additional spam checking methods like checking for an empty hidden field (since spambots tend to fill in all fields, also the non-visible), and the 3-second rule (where there is a check if there is a 3-second interval between the loading of the page and the submitting of the event, since spambots tend to work a lot faster than humans do). To use one or both of these extra checks, you can use the following code:');
        $code = '<input type="text" name="fields[hidden-field]" value="" style="display: none;" />
<input type="hidden" name="fields[timestamp]" value="{stop-forum-spam-timestamp}" />';
        $context['documentation'][] = contentBlueprintsEvents::processDocumentationCode($code);

        $context['documentation'][] = new XMLElement('p', '* Please note that to use the timestamp, you need to attach the stop-forum-spam-timestamp datasource.');
    }

    public function processEventData($context)
    {
        if (!in_array('stopforumspam', $context['event']->eParamFILTERS)) return;

        $mapping = $_POST['fields'];
        $check = array(
            'username' => $mapping[$this->username],
            'email' => $mapping[$this->email],
            'ip' => $_SERVER['REMOTE_ADDR'],
        );
        $result = $this->stopforumspamConnect($check);
        $text = null;

        $value = $result['success'] == 1;
        $fields = array();
        if($value == false)
        {
            foreach($result as $key => $value)
            {
                if(is_array($value))
                {
                    if($value['appears'] != 0)
                    {
                        $fields[] = $key;
                    }
                }
            }
            $text = explode(', ', $fields).' failed as spam';
        }

        if ($value == true) {
            // Check for timestamp (3 second interval):
            if (isset($mapping[$this->timestamp]) && time() < $mapping[$this->timestamp] + 3) {
                $value = false;
                $text = '3 second interval';
            }

            // Check for hidden field:
            if (isset($mapping[$this->hidden]) && !empty($mapping[$this->hidden])) {
                $value = false;
                $text .= $text == '' ? '' : ', ';
                $text = 'hidden field is not empty';
            }
        }

        $context['messages'][] = array('stopforumspam', $value, $text);
    }

    public function stopforumspamConnect($data)
    {
        if (!is_array($data)) return;

        $urloptions = '';

        foreach ($data as $key => $value) {
            if ($value) $urloptions .= '&' . urlencode($key) . '=' . urlencode($value);
        }
        $url = 'http://www.stopforumspam.com/api?f=xmldom' . $urloptions;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $xml = curl_exec($ch);
        curl_close($ch);

        $ret = simplexml_load_string($xml);

        return $this->objectsIntoArray($ret);
    }

    public function objectsIntoArray($data, $skipind = array())
    {
        $array = array();
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_object($value) || is_array($value)) {
                    $value = $this->objectsIntoArray($value, $skipind);
                }
                if (in_array($key, $skipind)) {
                    continue;
                }
                $array[$key] = $value;
            }
        }
        return $array;
    }
}

