import { readFileSync } from "node:fs";
import vm from "node:vm";
import assert from "node:assert/strict";
import { before, describe, it } from "node:test";

let game;
let documentElementOverrides = {};

function spy(implementation = () => undefined) {
  const calls = [];
  const fn = (...arguments_) => {
    calls.push(arguments_);
    return implementation(...arguments_);
  };
  fn.calls = calls;
  return fn;
}

before(() => {
  const source = readFileSync(new URL("../../thewalkingdeck.js", import.meta.url), "utf8");
  const dojo = {
    connect: spy(),
    disconnect: spy(),
  };
  const context = {
    console: { log: spy(), warn: spy() },
    document: {
      getElementById: spy((id) => documentElementOverrides[id]
        || { style: {}, classList: { add: spy(), remove: spy() } }),
      querySelectorAll: spy(() => []),
    },
    ebg: { core: { gamegui: function GameGui() {} } },
    getLibUrl: spy((name) => name),
    _: (message) => message,
    define: (_dependencies, factory) => {
      game = factory(
        dojo,
        (_name, _base, properties) => properties,
        context.ebg.core.gamegui,
        function Counter() {},
        {},
        {}
      );
    },
  };

  vm.runInNewContext(source, context, { filename: "thewalkingdeck.js" });
});

describe("card helpers", () => {
  it("hides the hand only while brainstorm cards are being reordered", () => {
    const brainstormWrap = { style: {} };
    documentElementOverrides.brainstorm_wrap = brainstormWrap;

    try {
      const context = {
        getActivePlayerId: spy(),
        setHandVisible: spy(),
        prepareBrainstormReorder: spy(),
        disableBrainstormReorder: spy(),
        brainstormAvailableDecks: [],
      };

      game.onEnteringState.call(context, "brainstormDeckChoice", {
        args: { availableDecks: [] },
      });
      game.onEnteringState.call(context, "brainstormReorder", {
        args: { cards: [] },
      });
      game.onLeavingState.call(context, "brainstormReorder");

      assert.equal(context.setHandVisible.calls.length, 2);
      assert.equal(context.setHandVisible.calls[0][0], false);
      assert.equal(context.setHandVisible.calls[1][0], true);
    } finally {
      delete documentElementOverrides.brainstorm_wrap;
    }
  });

  it("can permanently hide the hand when phase two starts", () => {
    const handWrap = { style: {} };
    documentElementOverrides.hand_wrap = handWrap;

    try {
      game.setHandVisible(false);
      assert.equal(handWrap.style.display, "none");
    } finally {
      delete documentElementOverrides.hand_wrap;
    }
  });

  for (const [wounds, expectedRotation] of [
    ["1", 1],
    [2, 2],
    ["3", 3],
  ]) {
    it(`rotates a character with ${wounds} wounds by ${expectedRotation} quarter-turns`, () => {
      assert.equal(
        game.getCharacterWoundsRotation({ is_character: "1", wounds }),
        expectedRotation
      );
    });
  }

  it("does not rotate characters without supported wounds or non-character cards", () => {
    assert.equal(game.getCharacterWoundsRotation({ is_character: "1", wounds: 0 }), 0);
    assert.equal(game.getCharacterWoundsRotation({ is_character: "0", wounds: 1 }), 0);
    assert.equal(game.getCharacterWoundsRotation({ is_character: "1", wounds: 4 }), 0);
    assert.equal(game.getCharacterWoundsRotation({ is_character: "1", wounds: null }), 0);
  });

  for (const [type, expectedType] of [
    ["2", "4"],
    ["3", "5"],
    ["1", "6"],
  ]) {
    it(`maps card type ${type} to fake type ${expectedType}`, () => {
      const actual = game.generateFakeCard({
        id: 10,
        type,
        location: "deck_rural",
        location_arg: 0,
      });
      assert.deepEqual(JSON.parse(JSON.stringify(actual)), {
        id: 10,
        type: expectedType,
        type_arg: "20",
        location: "deck_rural",
        location_arg: 0,
      });
    });
  }

  it("enforces the client-side destination rules", () => {
    const card = {
      consequence_black: { action: "nothing" },
      consequence_white: null,
      consequence_grey: { action: "draw", number: 1 },
    };

    assert.ok(game.canCardBePlayedInLocation(card, "escaped"));
    assert.ok(game.canCardBePlayedInLocation(card, "memory"));
    assert.equal(game.canCardBePlayedInLocation(card, "graveyard"), false);
    assert.equal(game.canCardBePlayedInLocation(card, "hand"), false);
    assert.equal(game.canCardBePlayedInLocation(null, "memory"), false);
  });

  it("moves a card to a known destination", async () => {
    const addCard = spy(async () => undefined);
    const sourceStock = {};
    const context = {
      getLocation: spy((location) => (location === "hand" ? sourceStock : { addCard })),
      generateFakeCard: game.generateFakeCard,
    };

    await game.moveCardToLocation.call(
      context,
      { id: 4, type: "2", location: "hand", location_arg: 0 },
      "memory"
    );

    assert.equal(addCard.calls.length, 1);
    assert.equal(addCard.calls[0][0].id, 4);
    assert.equal(addCard.calls[0][1].fromStock, sourceStock);
    assert.equal(addCard.calls[0][1].autoRemovePreviousCards, true);
  });
});

describe("player actions", () => {
  for (const [pendingAction, expectedTitle] of [
    ["applyGreyConsequence", "The grey consequence of ${card_name} will be applied"],
    ["placeCharacter", "${card_name} will be placed in the Characters area"],
  ]) {
    it(`shows and submits the ${pendingAction} confirmation`, () => {
      let confirmCallback;
      const statusBar = {
        setTitle: spy(),
        addActionButton: spy((_label, callback) => {
          confirmCallback = callback;
          return { style: {} };
        }),
      };
      const context = {
        statusBar,
        getActivePlayerId: () => 1,
        isCurrentPlayerActive: () => true,
        bgaPerformAction: spy(),
      };

      game.onUpdateActionButtons.call(context, "storyCheckPlayerChoice", {
        currentCard: { id: 8, card_name: "Ellie and Joel" },
        pendingAction,
      });

      assert.equal(statusBar.setTitle.calls[0][0], expectedTitle);
      assert.equal(statusBar.setTitle.calls[0][1].card_name, "Ellie and Joel");
      assert.equal(statusBar.addActionButton.calls[0][0], "Confirm");

      confirmCallback();
      assert.equal(context.bgaPerformAction.calls[0][0], "actStoryCheckPlayerChoice");
    });
  }

  it("does not offer a confirmation to resolve a non-character card", () => {
    const statusBar = {
      setTitle: spy(),
      addActionButton: spy(),
    };

    game.onUpdateActionButtons.call(
      {
        statusBar,
        getActivePlayerId: () => 1,
        isCurrentPlayerActive: () => true,
      },
      "storyCheckPlayerChoice",
      {
        currentCard: { id: 15, card_name: "Horse" },
        pendingAction: "resolveCard",
      }
    );

    assert.equal(statusBar.setTitle.calls.length, 0);
    assert.equal(statusBar.addActionButton.calls.length, 0);
  });

  it("previews one rotation for each wound assigned by clicking characters", () => {
    const updateCardInformations = spy();
    const context = {
      biteWoundsRemaining: 3,
      biteWoundsToApply: {},
      biteInitialWounds: { 8: 0, 9: 1 },
      biteEligibleCardIds: [8, 9],
      cardsManager: { updateCardInformations },
      isCurrentPlayerActive: () => true,
    };

    game.onBiteCharacterClick.call(context, { id: 8, wounds: 0 });
    game.onBiteCharacterClick.call(context, { id: 8, wounds: 1 });
    game.onBiteCharacterClick.call(context, { id: 9, wounds: 1 });

    assert.equal(context.biteWoundsRemaining, 0);
    assert.deepEqual(JSON.parse(JSON.stringify(context.biteWoundsToApply)), { 8: 2, 9: 1 });
    assert.deepEqual(
      updateCardInformations.calls.map((call) => call[0].wounds),
      [1, 2, 2]
    );
  });

  it("only requires the wounds that the characters can still receive", () => {
    const context = {};

    game.prepareBiteChoice.call(context, {
      bite: 3,
      eligibleCardsIds: [8],
      eligibleCharacters: [{ id: 8, wounds: 3 }],
    });

    assert.equal(context.biteWoundsRequired, 3);
    assert.equal(context.biteWoundsToAssign, 1);
    assert.equal(context.biteWoundsRemaining, 1);
  });

  it("only lets Robert receive a third wound", () => {
    const context = {};

    game.prepareBiteChoice.call(context, {
      bite: 2,
      eligibleCardsIds: [12],
      eligibleCharacters: [{ id: 12, card_name: "Robert", wounds: 2 }],
    });

    assert.equal(context.biteWoundsToAssign, 1);
    assert.equal(context.biteWoundsRemaining, 1);
  });

  it("turns a character face down on its fourth wound and stops further wounds", () => {
    const context = {
      biteWoundsRemaining: 1,
      biteWoundsToApply: {},
      biteInitialWounds: { 8: 3 },
      biteEligibleCardIds: [8],
      cardsManager: { updateCardInformations: spy() },
      isCurrentPlayerActive: () => true,
    };

    game.onBiteCharacterClick.call(context, { id: 8, wounds: 3 });
    game.onBiteCharacterClick.call(context, { id: 8, wounds: 4, face_down: true });

    assert.equal(context.biteWoundsRemaining, 0);
    assert.equal(context.cardsManager.updateCardInformations.calls.length, 1);
    assert.equal(context.cardsManager.updateCardInformations.calls[0][0].wounds, 4);
    assert.equal(context.cardsManager.updateCardInformations.calls[0][0].face_down, true);
  });

  it("submits all bite wound allocations once every wound is assigned", () => {
    const context = {
      biteWoundsRemaining: 0,
      biteWoundsToApply: { 8: 2, 9: 1 },
      bgaPerformAction: spy(),
    };

    game.confirmBiteWounds.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actApplyBiteWounds");
    assert.equal(
      context.bgaPerformAction.calls[0][1].wound_allocations,
      JSON.stringify({ 8: 2, 9: 1 })
    );
  });

  it("resets bite allocations and restores the original character display", () => {
    const initialCard = { id: 8, is_character: "1", wounds: 2, face_down: false };
    const updateCardInformations = spy();
    const context = {
      biteWoundsRequired: 2,
      biteWoundsToAssign: 2,
      biteWoundsRemaining: 0,
      biteWoundsToApply: { 8: 2 },
      biteInitialCharacters: { 8: initialCard },
      cardsManager: { updateCardInformations },
    };

    game.resetBiteWounds.call(context);

    assert.equal(context.biteWoundsRemaining, 2);
    assert.deepEqual(JSON.parse(JSON.stringify(context.biteWoundsToApply)), {});
    assert.equal(updateCardInformations.calls[0][0], initialCard);
  });

  it("selects and unselects up to the maximum number of characters to heal", () => {
    const highlights = new Map();
    const context = {
      healMaximumCharacters: 2,
      healEligibleCardIds: [8, 9, 10],
      healSelectedCardIds: [],
      isCurrentPlayerActive: () => true,
      setHealCharacterHighlighted: spy((cardId, highlighted) => {
        highlights.set(cardId, highlighted);
      }),
    };

    game.onHealCharacterClick.call(context, { id: 8, wounds: 2 });
    game.onHealCharacterClick.call(context, { id: 9, wounds: 0 });
    game.onHealCharacterClick.call(context, { id: 10, wounds: 1 });
    game.onHealCharacterClick.call(context, { id: 8, wounds: 2 });

    assert.deepEqual(Array.from(context.healSelectedCardIds), [9]);
    assert.equal(highlights.get(8), false);
    assert.equal(highlights.get(9), true);
    assert.equal(highlights.has(10), false);
  });

  it("submits the selected characters for healing", () => {
    const context = {
      healSelectedCardIds: [8, 9],
      bgaPerformAction: spy(),
    };

    game.confirmHealing.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actHealCharacters");
    assert.equal(
      context.bgaPerformAction.calls[0][1].selected_character_ids,
      "[8,9]"
    );
  });

  it("submits a selected protagonist", () => {
    const context = {
      hand: { getSelection: () => [{ id: 8, type: "1" }] },
      bgaPerformAction: spy(),
    };

    game.confirmProtagonistSelection.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actPlayProtagonistCard");
    assert.equal(context.bgaPerformAction.calls[0][1].card_id, 8);
  });

  it("draws from the selected deck", () => {
    const context = { bgaPerformAction: spy() };

    game.onRuralDeckCardClick.call(context);
    game.onUrbanDeckCardClick.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actDrawFromDeck");
    assert.equal(context.bgaPerformAction.calls[0][1].location, "deck_rural");
    assert.equal(context.bgaPerformAction.calls[1][0], "actDrawFromDeck");
    assert.equal(context.bgaPerformAction.calls[1][1].location, "deck_urban");
  });

  it("recovers only an eligible escaped card", () => {
    const context = {
      recoverChoiceActive: true,
      recoverEligibleCardIds: [12],
      bgaPerformAction: spy(),
    };

    game.onRecoverCardClick.call(context, { id: 13 });
    game.onRecoverCardClick.call(context, { id: 12 });

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actRecoverCard");
    assert.equal(context.bgaPerformAction.calls[0][1].card_id, 12);
  });

  it("does not play a hand card to escaped during a recover choice", () => {
    const context = {
      recoverChoiceActive: true,
      hand: { getSelection: spy(() => [{ id: 5 }]) },
      canCardBePlayedInLocation: spy(() => true),
      bgaPerformAction: spy(),
    };

    game.onEscapedClick.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 0);
  });

  it("only draws a disaster during phase two", () => {
    const context = { gamePhase: 1, bgaPerformAction: spy() };

    game.onDisasterBagClick.call(context);
    context.gamePhase = 2;
    game.onDisasterBagClick.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actDrawFromDisasterBag");
  });

  it("uses the disaster bag for the active disaster resolution draw", () => {
    const context = {
      gamePhase: 2,
      disasterResolutionPhase: "draw",
      bgaPerformAction: spy(),
    };

    game.onDisasterBagClick.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actDrawDisaster");
  });

  it("uses a separate action to draw for Wolf Trap during phase one", () => {
    const context = {
      gamePhase: 1,
      wolfTrapPhase: "draw",
      disasterResolutionPhase: null,
      bgaPerformAction: spy(),
    };

    game.onDisasterBagClick.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(
      context.bgaPerformAction.calls[0][0],
      "actDrawWolfTrapDisaster"
    );
  });

  it("resolves Wolf Trap through its dedicated player action", () => {
    let resolveCallback;
    const context = {
      statusBar: {
        setTitle: spy(),
        addActionButton: spy((_label, callback) => {
          resolveCallback = callback;
          return { style: {} };
        }),
      },
      getActivePlayerId: () => 1,
      isCurrentPlayerActive: () => true,
      bgaPerformAction: spy(),
    };

    game.onUpdateActionButtons.call(context, "wolfTrapChoice", {
      phase: "resolve",
      willBury: true,
    });

    assert.equal(
      context.statusBar.setTitle.calls[0][0],
      "The disaster has break and another symbol: bury Wolf Trap"
    );
    resolveCallback();
    assert.equal(context.bgaPerformAction.calls[0][0], "actResolveWolfTrap");
  });

  it("consumes only the matching resource during a disaster characteristic", () => {
    const context = {
      disasterResolutionPhase: "characteristic",
      disasterResourceAvailable: true,
      disasterResourceId: "ressource_hunger",
      bgaPerformAction: spy(),
    };

    game.onRessourceClick.call(context, { id: "ressource_break" });
    game.onRessourceClick.call(context, { id: "ressource_hunger" });

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actUseDisasterResource");
    assert.equal(
      context.bgaPerformAction.calls[0][1].token_id,
      "ressource_hunger"
    );
  });

  it("highlights the available resource for the current disaster characteristic", () => {
    const resourceElement = {
      classList: { add: spy(), remove: spy() },
    };
    documentElementOverrides["twd-ressource-ressource_hunger"] = resourceElement;

    try {
      const context = {
        clearDisasterResourceHighlight: game.clearDisasterResourceHighlight,
      };

      game.prepareDisasterChoice.call(context, {
        phase: "characteristic",
        resourceAvailable: true,
        resourceId: "ressource_hunger",
      });

      assert.equal(resourceElement.classList.add.calls.length, 1);
      assert.equal(resourceElement.classList.add.calls[0][0], "twd-highlight");
    } finally {
      delete documentElementOverrides["twd-ressource-ressource_hunger"];
    }
  });

  it("asks for confirmation after the final disaster draw", () => {
    let confirmCallback;
    const context = {
      statusBar: {
        setTitle: spy(),
        addActionButton: spy((_label, callback) => {
          confirmCallback = callback;
          return { style: {} };
        }),
      },
      getActivePlayerId: () => 1,
      isCurrentPlayerActive: () => true,
      bgaPerformAction: spy(),
    };

    game.onUpdateActionButtons.call(context, "disasterChoice", {
      phase: "draw",
      confirmedDraws: 0,
      requiredDraws: 2,
    });
    game.onUpdateActionButtons.call(context, "disasterChoice", {
      phase: "confirmDraw",
      confirmedDraws: 1,
      requiredDraws: 2,
    });

    assert.equal(context.statusBar.setTitle.calls[0][0], "Draw ${total} disasters");
    assert.equal(context.statusBar.setTitle.calls[0][1].total, 2);
    assert.equal(context.statusBar.setTitle.calls[1][0], "Draw ${total} disasters");
    assert.equal(context.statusBar.setTitle.calls[1][1].total, 2);
    assert.equal(context.statusBar.addActionButton.calls[0][0], "Confirm draw");
    confirmCallback();
    assert.equal(context.bgaPerformAction.calls[0][0], "actConfirmDisasterDraw");
  });

  it("confirms each disaster characteristic and announces affected characters", () => {
    let confirmCallback;
    const context = {
      statusBar: {
        setTitle: spy(),
        addActionButton: spy((_label, callback) => {
          confirmCallback = callback;
          return { style: {} };
        }),
      },
      getActivePlayerId: () => 1,
      isCurrentPlayerActive: () => true,
      bgaPerformAction: spy(),
    };

    game.onUpdateActionButtons.call(context, "disasterChoice", {
      phase: "characteristic",
      characteristic: "hunger",
      characteristicPresent: true,
      affectedCharacters: [{ card_name: "Glenn" }],
    });

    assert.equal(
      context.statusBar.setTitle.calls[0][0],
      "${characteristic} is present: ${characters} will receive 1 wound"
    );
    assert.equal(context.statusBar.addActionButton.calls[0][0], "Confirm");
    confirmCallback();
    assert.equal(
      context.bgaPerformAction.calls[0][0],
      "actConfirmDisasterCharacteristic"
    );
  });

  it("plays an eligible selected card to memory", () => {
    const card = { id: 3, consequence_white: { action: "nothing" } };
    const context = {
      hand: { getSelection: () => [card] },
      canCardBePlayedInLocation: game.canCardBePlayedInLocation,
      bgaPerformAction: spy(),
    };

    game.onMemoryClick.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actPlayCard");
    assert.equal(context.bgaPerformAction.calls[0][1].card_id, 3);
    assert.equal(context.bgaPerformAction.calls[0][1].location, "memory");
  });

  it("submits the card selected for Tallahassee's consequence", () => {
    const context = {
      hand: { getSelection: () => [{ id: 12, is_zombie: "1" }] },
      bgaPerformAction: spy(),
    };

    game.confirmZombieEscapeChoice.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actEscapeZombie");
    assert.equal(context.bgaPerformAction.calls[0][1].card_id, 12);
  });

  it("does not submit a non-zombie for Tallahassee's consequence", () => {
    const context = {
      hand: { getSelection: () => [{ id: 13, is_zombie: "0" }] },
      bgaPerformAction: spy(),
    };

    game.confirmZombieEscapeChoice.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 0);
  });

  it("confirms the top Memory card when Tallahassee finds an empty hand", () => {
    let confirmCallback;
    const statusBar = {
      setTitle: spy(),
      addActionButton: spy((_label, callback) => {
        confirmCallback = callback;
        return { style: {} };
      }),
    };
    const context = {
      statusBar,
      getActivePlayerId: () => 1,
      isCurrentPlayerActive: () => true,
      bgaPerformAction: spy(),
    };

    game.onUpdateActionButtons.call(context, "escapeTallaChoice", {
      canChooseMemory: true,
      mustChooseMemory: true,
      playableCardsIds: [],
    });

    assert.equal(
      statusBar.setTitle.calls[0][0],
      "The top card of the memory will be escaped"
    );
    assert.equal(statusBar.addActionButton.calls[0][0], "Confirm");

    confirmCallback();
    assert.equal(
      context.bgaPerformAction.calls[0][0],
      "actEscapeTallaFromMemory"
    );
  });

  it("chooses the top card of memory during Tallahassee's consequence", () => {
    const context = {
      zombieEscapeChoiceActive: true,
      escapeTallaMemoryAvailable: true,
      bgaPerformAction: spy(),
    };

    game.onMemoryClick.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actEscapeTallaFromMemory");
  });

  it("ignores memory when it is empty during Tallahassee's consequence", () => {
    const context = {
      zombieEscapeChoiceActive: true,
      escapeTallaMemoryAvailable: false,
      bgaPerformAction: spy(),
    };

    game.onMemoryClick.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 0);
  });

  it("starts brainstorm with the selected deck", () => {
    const context = { bgaPerformAction: spy() };

    game.onBrainstormRuralDeckClick.call(context);
    game.onBrainstormUrbanDeckClick.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actStartBrainstorm");
    assert.equal(context.bgaPerformAction.calls[0][1].location, "deck_rural");
    assert.equal(context.bgaPerformAction.calls[1][0], "actStartBrainstorm");
    assert.equal(context.bgaPerformAction.calls[1][1].location, "deck_urban");
  });

  it("confirms disaster resolution through the shared player action", () => {
    const context = { bgaPerformAction: spy() };

    game.resolveDisaster.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actResolveDisaster");
  });

  it("reads brainstorm cards from left to right", () => {
    const ids = game.getBrainstormCardIds([
      { id: "twd-card-8" },
      { id: "twd-card-3" },
      { id: "twd-card-11" },
    ]);

    assert.deepEqual(JSON.parse(JSON.stringify(ids)), [8, 3, 11]);
  });
});

describe("notifications", () => {
  it("waits until the face-down Memory card is installed when Story Check starts", async () => {
    let finishAdding;
    const addPromise = new Promise((resolve) => {
      finishAdding = resolve;
    });
    const memory = { addCard: spy(() => addPromise) };
    const card = { id: 18, type: "2", face_down: true };
    const context = { memory, setHandVisible: spy() };
    let notificationFinished = false;

    const notification = game.notif_storyCheckStarted.call(context, { memoryTopCard: card });
    notification.then(() => {
      notificationFinished = true;
    });
    await Promise.resolve();

    assert.equal(notificationFinished, false);
    assert.equal(memory.addCard.calls[0][0], card);
    assert.equal(context.gamePhase, 2);
    assert.equal(context.setHandVisible.calls[0][0], false);

    finishAdding();
    await notification;
    assert.equal(notificationFinished, true);
  });

  it("updates the protagonist and loss condition", () => {
    const context = {
      protagonistSlot: { addCard: spy() },
      hand: { removeAll: spy() },
      lossCondition: 5,
    };
    const card = { id: 2, type: "1" };

    game.notif_protagonistCardPlayed.call(context, { card, lossCondition: 3 });

    assert.equal(context.protagonistSlot.addCard.calls[0][0], card);
    assert.equal(context.protagonistSlot.addCard.calls[0][1].fromStock, context.hand);
    assert.equal(context.hand.removeAll.calls.length, 1);
    assert.equal(context.lossCondition, 3);
  });

  it("moves a drawn card between stocks", async () => {
    const source = {};
    const destination = { addCard: spy(async () => undefined) };
    const context = {
      getLocation: spy((location) => (location === "deck_urban" ? source : destination)),
    };
    const card = { id: 19 };

    await game.notif_cardDrawn.call(context, {
      card,
      source: "deck_urban",
      destination: "hand",
    });

    assert.equal(destination.addCard.calls[0][0], card);
    assert.equal(destination.addCard.calls[0][1].fromStock, source);
  });

  it("reveals and installs the current Story Check card", async () => {
    const memory = {
      setCardNumber: spy(),
      addCard: spy(async () => undefined),
      removeAll: spy(),
    };
    const storyCurrent = {
      addCard: spy(async () => undefined),
    };
    const context = { memory, storyCurrent };
    const card = { id: 21 };
    const memoryTopCard = { id: 15, type: "2", face_down: true };

    await game.notif_storyCardRevealed.call(context, {
      card,
      memoryTopCard,
      memoryNb: 2,
    });

    assert.equal(storyCurrent.addCard.calls[0][0], card);
    assert.equal(storyCurrent.addCard.calls[0][1].fromStock, memory);
    assert.equal(memory.setCardNumber.calls[0][0], 2);
    assert.equal(memory.addCard.calls[0][0], memoryTopCard);
  });

  it("moves a resolved character from the current Story card to the characters area", async () => {
    const storyCurrent = {};
    const characters = { addCard: spy(async () => undefined) };
    const context = { storyCurrent, characters };
    const card = { id: 8, is_character: "1" };

    await game.notif_characterPutInPlay.call(context, { card });

    assert.equal(characters.addCard.calls.length, 1);
    assert.equal(characters.addCard.calls[0][0], card);
    assert.equal(characters.addCard.calls[0][1].fromStock, storyCurrent);
  });

  it("updates a character after its wounds change", () => {
    const updateCardInformations = spy();
    const card = { id: 8, is_character: "1", wounds: 2 };

    game.notif_characterWoundsChanged.call(
      { cardsManager: { updateCardInformations } },
      { card }
    );

    assert.equal(updateCardInformations.calls[0][0], card);
  });
});
