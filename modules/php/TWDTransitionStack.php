<?php

namespace Bga\Games\TheWalkingDeck;


class TWDTransitionStack
{
  protected $game;

  public function __construct($game)
  {
    $this->game = $game;
  }

  public function pushTransition($transition)
  {
    switch ($transition) {
      case TWDTransition\PlayCards:
      case TWDTransition\AdditionalDrawCards:
      case TWDTransition\DrawCards:
        break;
      default:
        throw new \BgaUserException("Unknown transition to push : " . $transition);
    }
    $top = $this->game->getUniqueValueFromDB("SELECT MAX(`index`) FROM `twd_game_transition`") || 0;
    if ($top >= 15) {
      throw new \BgaUserException("Transition stack overflow : " . $top);
    }
    $newIndex = $top + 1;
    $this->game->DbQuery("INSERT INTO `twd_game_transition` (`index`, `transition_name`) VALUES ($newIndex, '$transition')");
  }

  public function popTransition()
  {
    $top = $this->game->getUniqueValueFromDB("SELECT `index`, `transition_name` FROM `twd_game_transition`");
    if ($top == null) {
      return null;
    } else {
      $this->game->DbQuery("DELETE FROM `twd_game_transition` WHERE `index` = $top");
      return $top['transition_name'];
    }
  }

  public function getCurrentTransition()
  {
    return $this->game->getUniqueValueFromDB("SELECT `transition_name` FROM `twd_game_transition` HAVING MAX(`index`)");
  }
}
