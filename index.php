<?php
require __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('America/Vancouver');
//error_reporting(0);

use \Trello\Trello;

$conf = parse_ini_file('config.ini');

$trello = new Trello($conf['key'], null, $conf['token']);

$boardsOptions = array();
$boardsOptions['boards']='all';

$listOptions = array();
$listOptions['filter']=  'open';
$listOptions['fields'] = 'closed,idBoard,name,pos';

$me = $trello->members->get('me',$boardsOptions);

// Loop through the boards
foreach ($me->boards as $boardId => $board ) {
      //
      if ($conf['boardToConvert'] != 'ALL' && $board->name != $conf['boardToConvert']) {
        continue;
      }

      $lists = $trello->get('boards/'.$board->id.'/lists',$listOptions);
      $membersById = getAllMembersById($trello, $board->id);
      $cardLists = getCardLists($trello, $board->id, $membersById);
      $relationships = getCardRelationships($trello, $board->id,$cardLists['allCardsByUrl']);
      generateMindMap($board->name, $lists, $membersById, $cardLists, $relationships, $conf);
    }




function random_color_part() {
    return str_pad( dechex( mt_rand( 150, 255 ) ), 2, '0', STR_PAD_LEFT);
}

function random_color() {
    return random_color_part() . random_color_part() . random_color_part();
}

function getCardRelationships($trello, $boardId, $allCardsByUrl) {
  $checklistOptions = array();
  $checklistOptions['checkItems']=  'all';

    $checklists = $trello->get('boards/'.$boardId.'/checklists',$checklistOptions);

    $relationships = array();
    foreach($checklists AS $index=>$checklist) {
        foreach($checklist->checkItems AS $index => $checkItem) {
          if (array_key_exists($checkItem->name,$allCardsByUrl)) {
            // This card has children

            $relationships[$checklist->idCard] = $checkItem->name;
          }
        }
    }

    return $relationships;

  }

function getAllMembersById($trello, $boardId) {
  $memberOptions = array();
  $memberOptions['fields']=  'all';

  $members = $trello->get('boards/'.$boardId.'/members',$memberOptions);

  $membersById = array();
  foreach($members as $index=>$member) {
    $membersById[$member->id] = $member->fullName;
  }

  return $membersById;
}

function getCardLists($trello, $boardId, $membersById) {
  $cardOptions = array();
  $cardOptions['filter'] = 'visible';
  $cardOptions['fields'] = 'id,name,url,due,dateLastActivity,closed,desc,idBoard,idList,labels,pos,idChecklists,idMembers,badges';
  $cardOptions['limit'] = 100; // get 100 cards at a time

  $allCards = getAllCards($trello, $boardId, $cardOptions);

  $cardLists = array();

  $allCardsByList = array(); // we later loop through each list and build the card nodes
  $allCardsByUrl = array(); // used to figure out the relationships

  foreach ($allCards AS $index=>$card) {

    $members = '';

    foreach ($card->idMembers as $index => $memberId) {
        $members .= empty($members)? $membersById[$memberId]:','.$membersById[$memberId];
    }

    $labels = '';
    foreach ($card->labels as $index => $label) {
      $labels .= empty($labels)? $label->name:','.$label->name;
    }


    $cardLists['allCardsByList'][$card->idList][$card->id] = array(
      'id' => $card->id,
      'name' => htmlspecialchars($card->name),
      'url' => $card->url,
      'desc' =>  htmlspecialchars($card->desc),
      'members' =>  htmlspecialchars($members),
      'labels' =>  htmlspecialchars($labels)
    );

    $complete = 0.00;
    if ($card->badges->checkItems > 0) {
      $complete = round(($card->badges->checkItemsChecked / $card->badges->checkItems) * 100,0);
    }

    $cardLists['allCardsByList'][$card->idList][$card->id]['completed'] = $complete;
    $cardLists['allCardsByList'][$card->idList][$card->id]['attachments'] = $card->badges->attachments;

    $cardLists['allCardsByUrl'][$card->url] = $allCardsByList[$card->idList][$card->id];
  }

  return $cardLists;

}

/*
   Boards are limited to a 1000 max per request. We need to grab them
   using pagngination.

   @return string
*/
function getAllCards($trello, $boardId, $cardOptions) {

      $cards = $trello->get('boards/'.$boardId.'/cards', $cardOptions);
      $allCards = $cards;
      // We will continue to pull cards as long as there are more than the limit
      // The first pass has to happen though.
      $continue = true;

      while ($continue) {
        // If the number of cards found is less than the limit, then we do can stop.
        if (sizeof($cards) < $cardOptions['limit']) {
            $continue = false;
        } else {
            $lastCardIdInFoundSet = $cards[0]->id;
            $cardOptions['before'] = $lastCardIdInFoundSet;
            $cards = $trello->get('boards/'.$boardId.'/cards', $cardOptions);

            $allCards = array_merge($allCards, $cards);
        }
      }

      return $allCards;

}

function generateMindMap($boardName, $lists, $membersById, $cardLists, $relationships, $conf) {
  // Generate the mindmap
  $map = '<map version="0.9.0">
  <attribute_registry SHOW_ATTRIBUTES="hide"/>
  <node TEXT="'.$boardName.'" STYLE="bubble" FOLDED="false">
    <edge COLOR="#B2B2FE" />';

    foreach ($lists as $index=>$list) {

        $position = 'right';
        if (($index+1)> sizeof($lists)/2) {
          $position = 'left';
        }
        $map .= '<node ID="'.$list->id.'" TEXT="'. htmlspecialchars($list->name).'" STYLE="bubble" FOLDED="false" POSITION="'.$position.'">
                <edge COLOR="'.random_color().'" />
        ';
        // list content
        if (is_array($cardLists['allCardsByList'][$list->id])) {
          foreach ($cardLists['allCardsByList'][$list->id] as $cardId=>$card) {
            if (in_array($list->name, $conf['listsConsideredCompleted'])) {
              $card['completed'] = 100;
            }
            $map .= generateSingleNode($card,$relationships);
          }
        }
        // end list content
        $map .= '</node>';
    }

  $map .= '</node></map>';

  // Remove anything which isn't a word, whitespace, number
  // or any of the following caracters -_~,;[]().
  // If you don't need to handle multi-byte characters
  // you can use preg_replace rather than mb_ereg_replace
  // Thanks @Łukasz Rysiak!
  $file = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $boardName);
  // Remove any runs of periods (thanks falstro!)
  $file = mb_ereg_replace("([\.]{2,})", '', $file);

  file_put_contents('mindmaps/'.$file.'-'.date('Y-m-d H-i').'.mm', $map);
}

function generateSingleNode($card,$relationships) {
  //print_r($card);exit;

  ob_start();
  //print_r($relationships);exit;
  ?>
  <node ID="<?php echo $card['url']?>" TEXT="<?php echo $card['name'];?>" LINK="<?php echo $card['url']?>" STYLE="bubble" FOLDED="false">
      <richcontent TYPE="NOTE"><?php echo $card['desc'];?></richcontent>
      <?php if ($card['completed'] == 100) { echo '<icon BUILTIN="button_ok"/>';} ?>
      <attribute NAME="Progress" VALUE="<?php echo $card['completed'];?>"/>
      <?php if (array_key_exists($card['id'], $relationships)) { ?>
        <arrowlink DESTINATION="<?php echo $relationships[$card['id']];?>" COLOR="#470000" STARTARROW="None" ENDARROW="Default" SOURCE_LABEL="" MIDDLE_LABEL="Related" TARGET_LABEL="" />
      <?php } ?>
      <?php if ($card['members'] != '') { ?>
        <node TEXT="<?php echo $card['members'];?>" STYLE="bubble" FOLDED="false" POSITION="right"/>
      <?php } ?>
      <?php if ($card['labels'] != '') { ?>
        <node TEXT="<?php echo $card['labels'];?>" STYLE="bubble" FOLDED="false" POSITION="right">
          <icon BUILTIN="messagebox_warning"/>
        </node>
      <?php } ?>
  </node>
  <?php
  return ob_get_clean();
}
