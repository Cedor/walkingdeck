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
  getLibUrl("bga-animations", "1.x"),
  getLibUrl("bga-cards", "1.x"),
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
            <div id="table-organiser">
              <div id="deck-rural-wrap" class="location-wrap">
                <b>${_("Rural Deck")}</b>
                <div id="deck-rural"></div>
              </div>
              <div id="deck-urban-wrap" class="location-wrap">
                <b>${_("Urban Deck")}</b>
                <div id="deck-urban"></div>
              </div>
                <div id="escaped-wrap" class="location-wrap">
                <b>${_("Escaped")}</b>
                <div id="escaped"></div>
              </div>
              <div id="memory-wrap" class="location-wrap">
                <b>${_("Memory")}</b>
                <div id="memory"></div>
              </div>
              <div id="protagonist-wrap" class="location-wrap">
                <b>${_("Protagonist")}</b>
                <div id="protagonist-slot">
                </div>
              </div>
              <div id="graveyard-wrap" class="location-wrap">
                <b>${_("Graveyard")}</b>
                <div id="graveyard"></div>
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
        type: "twd-card",
        getId: (card) => `card-${card.id}`,
        setupDiv: (card, div) => {
          div.classList.add("twd-card");
        },
        setupFrontDiv: (card, div) => {
          div.classList.add("twd-card-front");
          switch (card.type) {
            case "1":
              div.style.backgroundPositionY = `100%`;
              div.style.backgroundPositionX = `${((card.type_arg - 1) * 100) / 17}%`;
              break;
            case "2":
            case "3":
              div.style.backgroundPositionY = `${((card.type - 2) * 100) / 2}%`;
              div.style.backgroundPositionX = `${((card.type_arg - 1) * 100) / 17}%`;
              break;
            default:
              div.style.backgroundPosition = "-508px -358px";
          }
          this.addTooltipHtml(div.id, `tooltip de ${card.type}, ${card.type_arg}`);
        },
        setupBackDiv: (card, div) => {
          switch (card.type) {
            case "2": // rural
            case "4": // fake rural
              div.classList.add("twd-card-back-rural");
              break;
            case "3": // urban
            case "5": // fake urban
              div.classList.add("twd-card-back-urban");
              break;
            default:
              div.classList.add("twd-card-back");
          }
        },
        isCardVisible: (card) => {
          return card.type === "1" || card.type === "2" || card.type === "3";
        },
        cardWidth: 127,
        cardHeight: 179,
      });

      // create decks
      this.ruralDeck = new BgaCards.Deck(this.cardsManager, document.getElementById("deck-rural"), {
        cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {},
        fakeCardGenerator: (deckId) => {
          // Generate a fake card based on the original card
          return {
            id: `rural-top-card`,
            type: `4`, // fake rural
            location: `deck-rural`,
          };
        },
      });
      this.urbanDeck = new BgaCards.Deck(this.cardsManager, document.getElementById("deck-urban"), {
        cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {},
        fakeCardGenerator: (deckId) => {
          // Generate a fake card based on the original card
          return {
            id: `urban-top-card`,
            type: `5`, // fake urban
            location: `deck-urban`,
          };
        },
      });
      // create protagonist slot
      this.protagonistSlot = new BgaCards.SlotStock(this.cardsManager, document.getElementById("protagonist-slot"), {
        slotsIds: ["A"],
        slotClasses: ["twd-slot"],
        mapCardToSlot: (card) => "A",
      });
      this.protagonistSlot.onCardAdded = (card) => {
        console.log("Card added to protagonist slot", card);
      };
      // create hand
      this.hand = new BgaCards.HandStock(this.cardsManager, document.getElementById("hand"));
      this.hand.setSelectionMode("single");
      //create memory pile
      this.memory = new BgaCards.Deck(this.cardsManager, document.getElementById("memory"), {
        //TODO cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {
          hideWhenEmpty: true,
        },
      });
      //TODO this.memory.setSelectionMode("single");
      //create graveyard pile
      this.graveyard = new BgaCards.Deck(this.cardsManager, document.getElementById("graveyard"), {
        // TODO cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {
          hideWhenEmpty: true,
        },
      });
      // TODO this.graveyard.setSelectionMode("single");
      //create escaped pile
      this.escaped = new BgaCards.AllVisibleDeck(this.cardsManager, document.getElementById("escaped"), {
        //TODO cardClickEventFilter: "all",
        cardNumber: 0,
        counter: {
          hideWhenEmpty: true,
          position: "bottom",
        },
        direction: "horizontal",
      });
      //TODO this.escaped.setSelectionMode("multiple");

      // Set up game interface, according to "gamedatas"
      console.log("gamedatas", this.gamedatas);
      // Hand gamedatas
      for (var i in this.gamedatas.hand) this.hand.addCard(this.gamedatas.hand[i]);
      // Protagonist slot gamedatas
      let protagonistDatas = this.gamedatas.protagonistSlot;
      if (Object.keys(protagonistDatas).length > 1) console.log("Protagonist slot contains multiple cards");
      if (Object.keys(protagonistDatas).length > 0) {
        this.protagonistSlot.addCard(protagonistDatas[Object.keys(protagonistDatas)[0]]);
      } else {
        console.log("Protagonist slot is empty");
      }
      // Urban Deck gamedatas
      this.urbanDeck.setCardNumber(this.gamedatas.urbanDeckNb);
      // Rural Deck gamedatas
      this.ruralDeck.setCardNumber(this.gamedatas.ruralDeckNb);
      // Memory gamedatas
      console.log("Memory gamedatas", this.gamedatas.memory);
      if (this.gamedatas.memoryTop) {
        this.memory.setCardNumber(this.gamedatas.memoryNb - 1);
        this.memory.addCard(this.gamedatas.memoryTop);
      }
      // Graveyard gamedatas
      this.graveyard.setCardNumber(this.gamedatas.graveyardNb);
      console.log("Graveyard gamedatas", this.gamedatas.graveyardNb, this.gamedatas.graveyardTop);
      if (this.gamedatas.graveyardTop) {
        this.graveyard.setCardNumber(this.gamedatas.graveyardNb - 1);
        this.graveyard.addCard(this.gamedatas.graveyardTop);
      }
      // Escaped gamedatas
      for (var i in this.gamedatas.escaped) this.escaped.addCard(this.gamedatas.escaped[i]);
      // Setup connections
      this.setupConnections();
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
      console.log("Current active player is: " + this.getActivePlayerId());
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
      var player = this.getActivePlayerId();
      if (this.isCurrentPlayerActive()) {
        console.log("Current player is active");
        switch (stateName) {
          case "playCards":
            this.statusBar.addActionButton(_('Pass'), () => this.bgaPerformAction("actPass",{force:true}), { color: 'secondary' });
            break;
        }
      } else console.log("Current player is not active, active is " + player);
    },

    ///////////////////////////////////////////////////
    //// Utility methods

    /*
        
            Here, you can defines some utility methods that you can use everywhere in your javascript
            script.
        
        */
    getLocation: function (location) {
      switch (location) {
        case "hand":
          return this.hand;
        case "memory":
          return this.memory;
        case "graveyard":
          return this.graveyard;
        case "escaped":
          return this.escaped;
        default:
          console.log("Unknown location: " + location);
          return null;
      }
    },

    generateFakeCard: function (card) {
      // Generate a fake card based on the original card
      let fakeType;
      switch (card.type) {
        case "2": // rural
          fakeType = `4`; // fake rural
          break;
        case "3": // urban
          fakeType = `5`; // fake urban
          break;
        default:
          fakeType = `6`;
      }
      return {
        id: `fake-top-card`,
        type: fakeType,
        type_arg: `20`,
        location: card.location,
        location_arg: card.location_arg,
      };
    },
    ///////////////////////////////////////////////////
    //// Player's action

    /*
        
            Here, you are defining methods to handle player's action (ex: results of mouse click on 
            game objects).
            
            Most of the time, these methods:
            _ check the action is possible at this game state.
            _ make a call to the game server
        
        */
    setupConnections: function () {
      console.log("Setting up connections");
      dojo.connect(this.hand, "onSelectionChange", this, "onHandSelectionChange");
      dojo.connect(this.urbanDeck, "onCardClick", this, "onUrbanDeckCardClick");
      dojo.connect(this.ruralDeck, "onCardClick", this, "onRuralDeckCardClick");
      //dojo.connect(this.escaped, "onCardClick", this, "onEscapedClick");
      //dojo.connect(this.memory, "onCardClick", this, "onMemoryClick");
      //dojo.connect(this.graveyard, "onCardClick", this, "onGraveyardClick");
      dojo.connect(document.getElementById("escaped"), "onclick", this, "onEscapedClick");
      dojo.connect(document.getElementById("memory"), "onclick", this, "onMemoryClick");
      dojo.connect(document.getElementById("graveyard"), "onclick", this, "onGraveyardClick");
    },

    onHandSelectionChange: function (selectedCards, lastChange) {
      console.log("onHandSelectionChange", selectedCards);
      if (selectedCards.length === 0) {
        console.log("No card selected");
        return;
      } else {
        card = selectedCards[0];
        switch (card.type) {
          case "1": //protagonist => it's a protagonist pick
            this.bgaPerformAction("actPlayProtagonistCard", {
              card_id: card.id,
            });
            break;
          case "2": //rural => it's a rural deck pick
          case "3": //urban => it's an urban deck pick
            this.playingCardCheck();
            break;
          default:
            console.log("Unknown card type");
        }
      }
    },

    playingCardCheck: function () {
      console.log("playingCardCheck");
      let card = this.hand.getSelection()[0];
      if (card) {
        if (this.memory.getSelection().length > 0) {
          console.log("Memory selected, playing card");
        } else if (this.graveyard.getSelection().length > 0) {
          console.log("Graveyard selected, playing card");
        } else if (this.escaped.getSelection().length > 0) {
          console.log("Escaped selected, playing card");
        } else {
          console.log("No valid location selected");
        }
      } else console.log("No card selected");
      //this.bgaPerformAction("actPlayCard", {
      //  card_id: this.selectedCard.id,
      //});
    },
    onRuralDeckCardClick: function (card) {
      console.log("onRuralDeckCardClick");
      this.bgaPerformAction("actDrawFromRuralDeck");
    },
    onUrbanDeckCardClick: function (card) {
      console.log("onUrbanDeckCardClick");
      this.bgaPerformAction("actDrawFromUrbanDeck");
    },

    onEscapedClick: function () {
      console.log("onEscapedClick");
      let card = this.hand.getSelection()[0];
      if (card) {
        this.bgaPerformAction("actPlayCard", {
          card_id: card.id,
          location: "escaped",
        });
      } else console.log("No card selected");
    },
    onMemoryClick: function () {
      console.log("onMemoryClick");
      let card = this.hand.getSelection()[0];
      if (card) {
        this.bgaPerformAction("actPlayCard", {
          card_id: card.id,
          location: "memory",
        });
      } else console.log("No card selected");
    },
    onGraveyardClick: function () {
      console.log("onGraveyardClick");
      let card = this.hand.getSelection()[0];
      if (card) {
        this.bgaPerformAction("actPlayCard", {
          card_id: card.id,
          location: "graveyard",
        });
      } else console.log("No card selected");
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
      // table of notif type to delay in milliseconds
      const notifs = [
        ["protagonistCardPlayed", 100],
        ["cardDrawnFromRuralDeck", 100],
        ["cardDrawnFromUrbanDeck", 100],
        ["cardPlayed", 100],
      ];

      notifs.forEach((notif) => {
        dojo.subscribe(notif[0], this, `notif_${notif[0]}`);
        this.notifqueue.setSynchronous(notif[0], notif[1]);
        console.log(`Subscribed to notification ${notif[0]} with delay ${notif[1]}ms`);
      });
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
    notif_protagonistCardPlayed: function (notif) {
      console.log("notif_protagonistCardPlayed");
      console.log(notif);
      let card = notif.args.card;
      if (card) {
        this.protagonistSlot.addCard(card, { fromStock: this.hand });
        this.hand.removeAll();
      }
    },
    notif_cardDrawnFromRuralDeck: function (notif) {
      console.log("notif_cardDrawnFromRuralDeck");
      console.log(notif);
      let card = notif.args.card;
      console.log("Card drawn from rural deck", card);
      if (card) {
        this.hand.addCard(card, { fromStock: this.ruralDeck });
      }
    },
    notif_cardDrawnFromUrbanDeck: function (notif) {
      console.log("notif_cardDrawnFromUrbanDeck");
      console.log(notif);
      let card = notif.args.card;
      if (card) {
        this.hand.addCard(card, { fromStock: this.urbanDeck });
      }
    },
    notif_cardPlayed: function (notif) {
      console.log("notif_cardPlayed");
      // console.log(notif);
      let card = notif.args.card;
      let destination = this.getLocation(notif.args.location);
      console.log("Card played", card, "to location", notif.args.location);
      if (card && destination) {
        switch (notif.args.location) {
          case "memory":
            this.memory.addCard(card, { autoRemovePreviousCards: true, fromStock: this.hand });
            break;
          case "graveyard":
            this.hand.removeCard(card);
            let fakeCard = this.generateFakeCard(card);
            console.log("Fake card generated for graveyard", fakeCard);
            card.type = fakeCard.type;
            console.log("Card to be added to graveyard", card);
            this.graveyard.addCard(card, { autoRemovePreviousCards: true, fromStock: this.hand });
            break;
          case "escaped":
            this.escaped.addCard(card, { fromStock: this.hand });
            break;
          default:
            console.log("Unknown location for card played", notif.args.location);
        }
      }
    },
  });
});
