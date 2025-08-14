/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * TheWalkingDeck implementation : © <Cedor> <cedordev@gmail.com>
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * thewalkingdeck.js
 *
 * TheWalkingDeck user interface script
 *
 *
 */

define([
  "dojo",
  "dojo/_base/declare",
  "ebg/core/gamegui",
  "ebg/counter",
  getLibUrl('bga-animations', '1.x'),
  getLibUrl('bga-cards', '1.x')
], function (dojo, declare, gamegui, counter, BgaAnimations, BgaCards) {
  return declare("bgagame.thewalkingdeck", ebg.core.gamegui, {
    constructor: function () {
      console.log("thewalkingdeck constructor");
      this.cardwidth = 127;
      this.cardheight = 179;
    },

        /*
            setup:
            
            This method must set up the game user interface according to current game situation specified
            in parameters.
            
            The method is called each time the game interface is displayed to a player, ie:
            _ when the game starts
            _ when a player refreshes the game page (F5)
            
            "gamedatas" argument contains all datas retrieved by your "getAllDatas" PHP method.
        */

    setup: function (gamedatas) {
      console.log("Starting game setup");
      // Setting up the game interface
      var numPlayers = Object.keys(gamedatas.players).length;
      if (numPlayers > 1)
        console.warn(
          "TheWalkingDeck: game setup with more than 1 player is not supported yet. This is a single player game."
        );
      var player = Object.values(gamedatas.players)[numPlayers - 1];
      document.getElementById("game_play_area").insertAdjacentHTML(
        "beforeend",
        `
            <div id="player-table" class="whiteblock">
              <div class="playertablename" style="color:p0;">${player.name}</div>
              <div id="urban-deck-wrap">
                <b>${_("Urban Deck")}</b>
                <div id="urban-deck"></div>
              </div>
              <div id="rural-deck-wrap">
                <b>${_("Rural Deck")}</b>
                <div id="rural-deck"></div>
              </div>
              <div id="protagonist-wrap" >
                <b>${_("Protagonist")}</b>
                <div id="protagonist-slot">
                </div>
              </div>
              <div id="memory-wrap">
                <b>${_("Memory")}</b>
                <div id="memory"></div>
              </div>
              <div id="graveyard-wrap">
                <b>${_("Graveyard")}</b>
                <div id="graveyard"></div>
              </div>
              <div id="escaped-wrap">
                <b>${_("Escaped")}</b>
                <div id="escaped"></div>
              </div>
            </div>
          `
      );
      document.getElementById("game_play_area").insertAdjacentHTML(
        "beforeend",
        `<div id="hand_wrap" class="whiteblock">
            <b id="hand_label">${_("My hand")}</b>
            <div id="hand"></div>
          </div>`
      );

      // create the animation manager, and bind it to the `game.bgaAnimationsActive()` function
      this.animationManager = new BgaAnimations.Manager({
          animationsActive: () => this.bgaAnimationsActive(),
      });

      // create the card manager
      this.cardsManager = new BgaCards.Manager({
        animationManager: this.animationManager,
        type: 'twd-card',
        getId: (card) => `card-${card.id}`,
        setupDiv: (card, div) => {
          div.classList.add("twd-card");
          //div.style.width = "127px";
          //div.style.height = "179px";
          div.style.position = "relative";
        },
        setupFrontDiv: (card, div) => {
         /* div.classList.remove("twd-card-back");
          div.classList.remove("twd-card-back-urban");
          div.classList.remove("twd-card-back-rural");
          div.classList.add("twd-card-front");*/
          //div.id = `card-${card.id}-front`;
          div.style.backgroundPositionX = `${(card.type_arg-1) * 100 / 17}%`;
          if (card.type == 1)
            div.style.backgroundPositionY = `100%`;  
          else
            div.style.backgroundPositionY = `${(card.type - 2) * 100 / 2}%`;
          this.addTooltipHtml(div.id, `tooltip de ${card.type}, ${card.type_arg}`);
        },
        setupBackDiv: (card, div) => {
         // div.classList.remove("twd-card-front");
          switch (card.type) {
            case "2": // urban
              div.classList.add("twd-card-back-urban");
              break;
            case "3": // rural
              div.classList.add("twd-card-back-rural");
              break;
            default:
              div.classList.add("twd-card-back");
          }
        },
        isCardVisible: (card) => Boolean(card.type),
        cardWidth: 127,
        cardHeight: 179,
      });

      // create decks
      this.urbanDeck = new BgaCards.Deck(
        this.cardsManager,
        document.getElementById("urban-deck"), {
          cardNumber: 0,
          counter: {
            position: 'center',
            extraClasses: 'text-shadow',
          }
        }
      );
      this.ruralDeck = new BgaCards.Deck(
        this.cardsManager,
        document.getElementById("rural-deck"), {
          cardNumber: 0,
          counter: {
            position: 'center',
            extraClasses: 'text-shadow',
          }
        }
      );
      // create protagonist slot
      this.protagonistSlot = new BgaCards.SlotStock(
        this.cardsManager,
        document.getElementById('protagonist-slot'), {
          slotsIds: ['A'],
          slotClasses: ['twd-slot'],
          mapCardToSlot: (card) => card.location,
        }
      );
      // create hand
      this.hand = new BgaCards.HandStock(
        this.cardsManager,
        document.getElementById('hand')
      );
      //create memory pile
      this.memory = new BgaCards.DiscardDeck(
        this.cardsManager,
        document.getElementById('memory'),
        {
          cardNumber: 0,
          counter: {
            hideWhenEmpty: true,
          },
        }
      );
      //create graveyard pile
      this.graveyard = new BgaCards.Deck(
        this.cardsManager,
        document.getElementById('graveyard'),
        {
          cardNumber: 0,
          counter: {
            hideWhenEmpty: true,
          },
        }
      );
      //create escaped pile
      this.escaped = new BgaCards.AllVisibleDeck(
        this.cardsManager,
        document.getElementById('escaped'),{
          cardNumber: 0,
          counter: {
            hideWhenEmpty: true,
          },
        }
      );
      
      // Set up game interface, according to "gamedatas"
      // Hand gamedatas
      for (var i in this.gamedatas.hand)
        this.hand.addCard(this.gamedatas.hand[i]);
      // Protagonist slot gamedatas
      if (this.gamedatas.protagonistSlot.length > 1) 
        console.log("Protagonist slot contains multiple cards");
      if (this.gamedatas.protagonistSlot.length > 0)
        this.protagonistSlot.addCard(this.gamedatas.protagonistSlot[0]);
      // Urban Deck gamedatas
      this.urbanDeck.setCardNumber(this.gamedatas.urbanDeckNb);
      // Rural Deck gamedatas
      this.ruralDeck.setCardNumber(this.gamedatas.ruralDeckNb);
      // Memory gamedatas
      if (this.gamedatas.memoryTop) {
        this.memory.setCardNumber(this.gamedatas.memory-1);
        this.memory.addCard(this.gamedatas.memoryTop);
      }
      // Graveyard gamedatas
      this.graveyard.setCardNumber(this.gamedatas.graveyardNb);
      // Escaped gamedatas
      for (var i in this.gamedatas.escaped)
        this.escaped.addCard(this.gamedatas.escaped[i])

      // Setup hand action
      dojo.connect(this.hand, "onCardClick", this, "onCardClick");

      // Setup game notifications to handle (see "setupNotifications" method below)
      this.setupNotifications();

      console.log("Ending game setup");
    },

    ///////////////////////////////////////////////////
    //// Game & client states

    // onEnteringState: this method is called each time we are entering into a new game state.
    //                  You can use this method to perform some user interface changes at this moment.
    //
    onEnteringState: function (stateName, args) {
      console.log("Entering state: " + stateName, args);

      switch (stateName) {
        /* Example:
            
            case 'myGameState':
            
                // Show some HTML block at this game state
                dojo.style( 'my_html_block_id', 'display', 'block' );
                
                break;
           */

        case "dummy":
          break;
      }
    },

    // onLeavingState: this method is called each time we are leaving a game state.
    //                 You can use this method to perform some user interface changes at this moment.
    //
    onLeavingState: function (stateName) {
      console.log("Leaving state: " + stateName);

      switch (stateName) {
        /* Example:
            
            case 'myGameState':
            
                // Hide the HTML block we are displaying only during this game state
                dojo.style( 'my_html_block_id', 'display', 'none' );
                
                break;
           */

        case "dummy":
          break;
      }
    },

    // onUpdateActionButtons: in this method you can manage "action buttons" that are displayed in the
    //                        action status bar (ie: the HTML links in the status bar).
    //
    onUpdateActionButtons: function (stateName, args) {
      console.log("onUpdateActionButtons: " + stateName, args);

      if (this.isCurrentPlayerActive()) {
        switch (stateName) {
          case "playerTurn":
            const playableCardsIds = args.playableCardsIds; // returned by the argPlayerTurn

            // Add test action buttons in the action status bar, simulating a card click:
            playableCardsIds.forEach((cardId) =>
              this.statusBar.addActionButton(
                _("Play card with id ${card_id}").replace("${card_id}", cardId),
                () => this.onCardClick(cardId)
              )
            );

            this.statusBar.addActionButton(
              _("Pass"),
              () => this.bgaPerformAction("actPass"),
              { color: "secondary" }
            );
            break;
        }
      }
    },

    ///////////////////////////////////////////////////
    //// Utility methods

    /*
        
            Here, you can defines some utility methods that you can use everywhere in your javascript
            script.
        
        */

    ///////////////////////////////////////////////////
    //// Player's action

    /*
        
            Here, you are defining methods to handle player's action (ex: results of mouse click on 
            game objects).
            
            Most of the time, these methods:
            _ check the action is possible at this game state.
            _ make a call to the game server
        
        */

    onCardClick: function (card) {
      var card_id = card.id;
      console.log("onCardClick", card_id);

      this.bgaPerformAction("actPlayProtagonistCard", {
        card_id,
      }).then(() => {
        // What to do after the server call if it succeeded
        // (most of the time, nothing, as the game will react to notifs / change of state instead)
      });

      this.bgaPerformAction("actPlayCard", {
        card_id,
      }).then(() => {
        // What to do after the server call if it succeeded
        // (most of the time, nothing, as the game will react to notifs / change of state instead)
      });
    },

    ///////////////////////////////////////////////////
    //// Reaction to cometD notifications

    /*
            setupNotifications:
            
            In this method, you associate each of your game notifications with your local method to handle it.
            
            Note: game notification names correspond to "notifyAllPlayers" and "notifyPlayer" calls in
                  your thewalkingdeck.game.php file.
        
        */
    setupNotifications: function () {
      console.log("notifications subscriptions setup");

      // TODO: here, associate your game notifications with local methods

      // Example 1: standard notification handling
      // dojo.subscribe( 'cardPlayed', this, "notif_cardPlayed" );

      // Example 2: standard notification handling + tell the user interface to wait
      //            during 3 seconds after calling the method in order to let the players
      //            see what is happening in the game.
      // dojo.subscribe( 'cardPlayed', this, "notif_cardPlayed" );
      // this.notifqueue.setSynchronous( 'cardPlayed', 3000 );
      //
    },

    // TODO: from this point and below, you can write your game notifications handling methods

    /*
        Example:
        
        notif_cardPlayed: function( notif )
        {
            console.log( 'notif_cardPlayed' );
            console.log( notif );
            
            // Note: notif.args contains the arguments specified during you "notifyAllPlayers" / "notifyPlayer" PHP call
            
            // TODO: play the card in the user interface.
        },    
        
        */
  });
});
