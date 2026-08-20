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
  it("enables clicks on the protagonist slot for protagonist abilities", () => {
    const source = readFileSync(
      new URL("../../thewalkingdeck.js", import.meta.url),
      "utf8"
    );
    const protagonistSlotSetup = source.slice(
      source.indexOf("this.protagonistSlot = new BgaCards.SlotStock"),
      source.indexOf("// Create hand")
    );

    assert.match(protagonistSlotSetup, /cardClickEventFilter:\s*"all"/);
  });

  it("renders the brainstorm area above the hand", () => {
    const source = readFileSync(
      new URL("../../thewalkingdeck.js", import.meta.url),
      "utf8"
    );

    assert.ok(
      source.indexOf('<div id="brainstorm_wrap"')
        < source.indexOf('<div id="hand_wrap"')
    );
  });

  it("keeps the hand visible while brainstorm cards are being reordered", () => {
    const brainstormWrap = { style: {} };
    documentElementOverrides.brainstorm_wrap = brainstormWrap;

    try {
      const context = {
        getActivePlayerId: spy(),
        prepareBrainstormReorder: spy(),
        disableBrainstormReorder: spy(),
        brainstormAvailableDecks: [],
        enableDeckSelection: spy(),
      };

      game.onEnteringState.call(context, "brainstormDeckChoice", {
        args: { availableDecks: [] },
      });
      game.onEnteringState.call(context, "brainstormReorder", {
        args: { cards: [] },
      });
      game.onLeavingState.call(context, "brainstormReorder");

      assert.equal(context.prepareBrainstormReorder.calls.length, 1);
      assert.equal(brainstormWrap.style.display, "none");
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

  it("restores the phase two areas from numeric gamedata", () => {
    const resolutionOrganiser = { style: {} };
    documentElementOverrides.card_resolution_organiser = resolutionOrganiser;
    const context = { setHandVisible: spy() };

    try {
      game.setGamePhaseDisplay.call(context, 2);

      assert.equal(context.gamePhase, 2);
      assert.equal(context.setHandVisible.calls[0][0], false);
      assert.equal(resolutionOrganiser.style.display, "block");
    } finally {
      delete documentElementOverrides.card_resolution_organiser;
    }
  });

  it("reuses the brainstorm area for unremember choices", () => {
    const brainstormWrap = { style: {} };
    documentElementOverrides.brainstorm_wrap = brainstormWrap;

    try {
      const context = {
        getActivePlayerId: spy(),
        prepareUnrememberChoice: spy(),
        disableUnrememberChoice: spy(),
      };

      game.onEnteringState.call(context, "unrememberChoice", {
        args: { cards: [{ id: 3 }], maximumCards: 1 },
      });
      game.onLeavingState.call(context, "unrememberChoice");

      assert.equal(context.prepareUnrememberChoice.calls.length, 1);
      assert.equal(context.prepareUnrememberChoice.calls[0][1], 1);
      assert.equal(context.disableUnrememberChoice.calls.length, 1);
      assert.equal(brainstormWrap.style.display, "none");
    } finally {
      delete documentElementOverrides.brainstorm_wrap;
    }
  });

  it("uses native deck selection during brainstorm deck choice", () => {
    const urbanDeck = {
      setSelectionMode: spy(),
      unselectAll: spy(),
      onSelectionChange: null,
    };
    const ruralDeck = {
      setSelectionMode: spy(),
      unselectAll: spy(),
      onSelectionChange: null,
    };
    const context = {
      urbanDeck,
      ruralDeck,
      brainstormAvailableDecks: [],
      onBrainstormUrbanDeckClick: game.onBrainstormUrbanDeckClick,
      onBrainstormRuralDeckClick: game.onBrainstormRuralDeckClick,
      bgaPerformAction: spy(),
      getActivePlayerId: spy(),
      enableDeckSelection: game.enableDeckSelection,
    };

    game.onEnteringState.call(context, "brainstormDeckChoice", {
      args: { availableDecks: ["deck_urban", "deck_rural"] },
    });

    assert.equal(urbanDeck.setSelectionMode.calls[0][0], "single");
    assert.equal(ruralDeck.setSelectionMode.calls[0][0], "single");

    urbanDeck.onSelectionChange([{ id: "urban-top-card" }]);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actStartBrainstorm");
    assert.equal(
      context.bgaPerformAction.calls[0][1].location,
      "deck_urban"
    );
  });

  it("coordinates native selection across the two deck stocks", () => {
    const ruralDeck = {
      setSelectionMode: spy(),
      unselectAll: spy(),
      onSelectionChange: null,
    };
    const urbanDeck = {
      setSelectionMode: spy(),
      unselectAll: spy(),
      onSelectionChange: null,
    };
    const onSelected = spy();
    const context = { ruralDeck, urbanDeck };

    game.enableDeckSelection.call(
      context,
      ["deck_rural"],
      onSelected
    );

    assert.equal(ruralDeck.setSelectionMode.calls[0][0], "single");
    assert.equal(urbanDeck.setSelectionMode.calls[0][0], "none");

    ruralDeck.onSelectionChange([{ id: "rural-top-card" }]);

    assert.equal(onSelected.calls[0][0], "deck_rural");
    assert.equal(urbanDeck.unselectAll.calls.length, 2);

    game.disableDeckSelection.call(context);

    assert.equal(ruralDeck.setSelectionMode.calls.at(-1)[0], "none");
    assert.equal(urbanDeck.setSelectionMode.calls.at(-1)[0], "none");
    assert.equal(ruralDeck.onSelectionChange, null);
    assert.equal(urbanDeck.onSelectionChange, null);
  });

  for (const [stateName, stateArgs, actionName] of [
    ["drawCards", {}, "actDrawFromDeck"],
    [
      "fastMemoriseDeckChoice",
      { args: { availableDecks: ["deck_rural"] } },
      "actFastMemorise",
    ],
  ]) {
    it(`uses native deck selection during ${stateName}`, () => {
      const ruralDeck = {
        setSelectionMode: spy(),
        unselectAll: spy(),
        onSelectionChange: null,
      };
      const urbanDeck = {
        setSelectionMode: spy(),
        unselectAll: spy(),
        onSelectionChange: null,
      };
      const context = {
        ruralDeck,
        urbanDeck,
        enableDeckSelection: game.enableDeckSelection,
        onRuralDeckCardClick: game.onRuralDeckCardClick,
        onUrbanDeckCardClick: game.onUrbanDeckCardClick,
        onFastMemoriseRuralDeckClick: game.onFastMemoriseRuralDeckClick,
        onFastMemoriseUrbanDeckClick: game.onFastMemoriseUrbanDeckClick,
        bgaPerformAction: spy(),
        getActivePlayerId: spy(),
      };

      game.onEnteringState.call(context, stateName, stateArgs);
      ruralDeck.onSelectionChange([{ id: "rural-top-card" }]);

      assert.equal(context.bgaPerformAction.calls[0][0], actionName);
      assert.equal(
        context.bgaPerformAction.calls[0][1].location,
        "deck_rural"
      );
    });
  }

  for (const [stateName, selectedProperty, buttonId] of [
    [
      "buryTopCardChoice",
      "buryTopCardSelectedDeck",
      "confirm_bury_top_card",
    ],
    [
      "avoidDeckChoice",
      "avoidDeckSelectedDeck",
      "confirm_avoid_deck",
    ],
  ]) {
    it(`keeps confirmation after native selection during ${stateName}`, () => {
      const ruralDeck = {
        setSelectionMode: spy(),
        unselectAll: spy(),
        onSelectionChange: null,
      };
      const urbanDeck = {
        setSelectionMode: spy(),
        unselectAll: spy(),
        onSelectionChange: null,
      };
      const button = { style: {} };
      documentElementOverrides[buttonId] = button;
      const context = {
        ruralDeck,
        urbanDeck,
        enableDeckSelection: game.enableDeckSelection,
        selectBuryTopCardDeck: game.selectBuryTopCardDeck,
        selectAvoidDeck: game.selectAvoidDeck,
        getActivePlayerId: spy(),
      };

      try {
        game.onEnteringState.call(context, stateName, {
          args: { availableDecks: ["deck_rural", "deck_urban"] },
        });
        ruralDeck.onSelectionChange([{ id: "rural-top-card" }]);

        assert.equal(context[selectedProperty], "deck_rural");
        assert.equal(button.style.visibility, "visible");
      } finally {
        delete documentElementOverrides[buttonId];
      }
    });
  }

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

  it("rotates Aenor by one quarter-turn after her ability is used", () => {
    assert.equal(game.getCardRotation({
      type: "1",
      type_arg: "1",
      aenor_ability_used: true,
    }), 1);
    assert.equal(game.getCardRotation({
      type: "1",
      type_arg: "1",
      aenor_ability_used: false,
    }), 0);
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

  it("generates unique fake deck cards for shuffle animations", () => {
    const first = game.generateDeckFakeCard(
      "deck_rural-shuffle-0",
      "4",
      "deck_rural"
    );
    const second = game.generateDeckFakeCard(
      "deck_rural-shuffle-1",
      "4",
      "deck_rural"
    );

    assert.equal(first.id, "deck_rural-shuffle-0-4-top-card");
    assert.equal(second.id, "deck_rural-shuffle-1-4-top-card");
    assert.notEqual(first.id, second.id);
    assert.equal(
      game.generateDeckFakeCard("deck_rural", "3", "deck_rural").type,
      "5"
    );
    assert.equal(game.generateDeckFakeCard("memory", "3", "memory").type, "5");
    assert.equal(
      game.generateDeckFakeCard("graveyard", "2", "graveyard").type,
      "4"
    );
  });

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

  it("highlights a special draw moved to memory", async () => {
    const highlightedCard = {
      classList: { add: spy(), remove: spy() },
    };
    documentElementOverrides["twd-card-13"] = highlightedCard;
    const context = {
      getLocation: spy(() => ({ addCard: spy(async () => undefined) })),
      clearSpecialDrawHighlight: game.clearSpecialDrawHighlight,
    };

    try {
      await game.moveCardToLocation.call(
        context,
        { id: 13, type: "2" },
        "memory",
        "deck_rural",
        true
      );

      assert.equal(context.specialDrawHighlightedCardId, 13);
      assert.equal(highlightedCard.classList.add.calls[0][0], "twd-highlight");
    } finally {
      delete documentElementOverrides["twd-card-13"];
    }
  });
});

describe("player actions", () => {
  it("selects the character protected by Aenor", () => {
    const context = {
      aenorSelectingCharacter: true,
      aenorAbilityAvailable: true,
      aenorEligibleCharacterIds: [8, 9],
      aenorDamageState: "biteChoice",
      biteWoundsRemaining: 0,
      bgaPerformAction: spy(),
      setAenorCharacterHighlighted: spy(),
      removeAenorCancelButton: spy(),
      restoreAenorDamageUi: spy(),
    };

    const handled = game.onAenorCharacterClick.call(context, { id: 8 });

    assert.equal(handled, true);
    assert.equal(context.bgaPerformAction.calls.length, 0);
    assert.equal(context.aenorAbilityAvailable, false);
    assert.equal(context.aenorPendingCharacterId, 8);
    assert.equal(context.aenorProtectedCharacterId, 8);
    assert.equal(context.removeAenorCancelButton.calls.length, 0);
    assert.equal(context.restoreAenorDamageUi.calls.length, 1);
  });

  it("cancels Aenor after a protected character was selected", () => {
    const updateCardInformations = spy();
    const setAenorCharacterHighlighted = spy();
    const context = {
      aenorSelectingCharacter: false,
      aenorAbilityAvailable: false,
      aenorPendingCharacterId: 8,
      aenorProtectedCharacterId: 8,
      aenorEligibleCharacterIds: [8, 9],
      protagonistSlot: {
        getCards: () => [{ id: 1, type: "1", type_arg: "1" }],
      },
      cardsManager: { updateCardInformations },
      setAenorCharacterHighlighted,
      restoreAenorDamageUi: spy(),
      removeAenorCancelButton: spy(),
    };

    game.cancelAenorAbility.call(context);

    assert.equal(context.aenorAbilityAvailable, true);
    assert.equal(context.aenorPendingCharacterId, 0);
    assert.equal(context.aenorProtectedCharacterId, 0);
    assert.equal(updateCardInformations.calls[0][0].aenor_ability_used, false);
    assert.deepEqual(setAenorCharacterHighlighted.calls, [
      [8, false],
      [9, false],
    ]);
    assert.equal(context.restoreAenorDamageUi.calls.length, 1);
  });

  it("starts Aenor character selection from the protagonist card", () => {
    const updateCardInformations = spy();
    const setAenorCharacterHighlighted = spy();
    let cancelCallback;
    const protagonist = {
      id: 1,
      type: "1",
      type_arg: "1",
    };
    const context = {
      aenorAbilityAvailable: true,
      aenorEligibleCharacterIds: [8],
      isCurrentPlayerActive: () => true,
      cardsManager: { updateCardInformations },
      protagonistSlot: { getCards: () => [protagonist] },
      setAenorCharacterHighlighted,
      statusBar: {
        setTitle: spy(),
        addActionButton: spy((_label, callback) => {
          cancelCallback = callback;
          return { style: {} };
        }),
      },
      aenorDamageState: "biteChoice",
      aenorDamageArgs: { bite: 1 },
      biteWoundsRequired: 1,
      biteWoundsToAssign: 1,
      biteWoundsRemaining: 1,
      cancelAenorAbility: game.cancelAenorAbility,
      restoreAenorDamageUi: game.restoreAenorDamageUi,
      removeAenorCancelButton: game.removeAenorCancelButton,
    };

    game.onAenorProtagonistClick.call(context, protagonist);

    assert.equal(context.aenorSelectingCharacter, true);
    assert.equal(updateCardInformations.calls[0][0].aenor_ability_used, true);
    assert.equal(setAenorCharacterHighlighted.calls[0][0], 8);
    assert.equal(setAenorCharacterHighlighted.calls[0][1], true);
    assert.equal(
      context.statusBar.addActionButton.calls[0][0],
      "Cancel Aenor ability"
    );

    cancelCallback();

    assert.equal(context.aenorSelectingCharacter, false);
    assert.equal(updateCardInformations.calls[1][0].aenor_ability_used, false);
    assert.deepEqual(setAenorCharacterHighlighted.calls[1], [8, false]);
  });

  it("shows and submits the grey consequence confirmation", () => {
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
        getCardName: game.getCardName,
        getActivePlayerId: () => 1,
        isCurrentPlayerActive: () => true,
        bgaPerformAction: spy(),
      };

      game.onUpdateActionButtons.call(context, "storyCheckPlayerChoice", {
        currentCard: { id: 8, card_name: "Ellie and Joel" },
        pendingAction: "applyGreyConsequence",
      });

      assert.equal(
        statusBar.setTitle.calls[0][0],
        "The grey consequence of ${card_name} will be applied"
      );
      assert.equal(statusBar.setTitle.calls[0][1].card_name, "Ellie and Joel");
      assert.equal(statusBar.addActionButton.calls[0][0], "Confirm");

      confirmCallback();
      assert.equal(context.bgaPerformAction.calls[0][0], "actStoryCheckPlayerChoice");
  });

  it("shows and submits the card burial confirmation", () => {
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
      getCardName: game.getCardName,
      getActivePlayerId: () => 1,
      isCurrentPlayerActive: () => true,
      bgaPerformAction: spy(),
    };

    game.onUpdateActionButtons.call(context, "cardBurialConfirmation", {
      sourceCard: { id: 13, card_name: "Brigade" },
    });

    assert.equal(statusBar.setTitle.calls[0][0], "Card ${card_name} will be buried");
    assert.equal(statusBar.setTitle.calls[0][1].card_name, "Brigade");
    assert.equal(statusBar.addActionButton.calls[0][0], "Confirm");

    confirmCallback();
    assert.equal(context.bgaPerformAction.calls[0][0], "actConfirmCardBurial");
  });

  it("returns translated card names and safely falls back for unknown cards", () => {
    assert.equal(game.getCardName({ card_name: "Wolf Trap" }), "Wolf Trap");
    assert.equal(game.getCardName({ card_name: "Custom card" }), "Custom card");
    assert.equal(game.getCardName(null), "Unknown card");
  });

  it("does not offer a confirmation to resolve a non-character card", () => {
    const statusBar = {
      setTitle: spy(),
      addActionButton: spy(),
    };

    game.onUpdateActionButtons.call(
      {
        statusBar,
        getCardName: game.getCardName,
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
    const updateCardInformations = spy();
    const context = {
      getActivePlayerId: spy(),
      characters: {},
      prepareBiteChoice: game.prepareBiteChoice,
      prepareAenorAbility: spy(),
      onBiteCharacterClick: game.onBiteCharacterClick,
      cardsManager: { updateCardInformations },
      isCurrentPlayerActive: () => true,
    };

    game.onEnteringState.call(context, "biteChoice", {
      args: {
        bite: 3,
        eligibleCardsIds: [12],
        eligibleCharacters: [{ id: 12, card_name: "Robert", wounds: 2 }],
      },
    });

    assert.equal(context.biteWoundsToAssign, 1);
    assert.equal(context.biteWoundsRemaining, 1);

    context.characters.onCardClick({
      id: 12,
      card_name: "Robert",
      wounds: 2,
    });

    assert.equal(context.biteWoundsRemaining, 0);
    assert.deepEqual(
      JSON.parse(JSON.stringify(context.biteWoundsToApply)),
      { 12: 1 }
    );
    assert.equal(updateCardInformations.calls[0][0].wounds, 3);
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

  it("submits all bite wound allocations once every wound is assigned", async () => {
    const context = {
      biteWoundsRemaining: 0,
      biteWoundsToApply: { 8: 2, 9: 1 },
      bgaPerformAction: spy(),
      submitPendingAenorAbility: game.submitPendingAenorAbility,
      removeAenorCancelButton: spy(),
    };

    await game.confirmBiteWounds.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actApplyBiteWounds");
    assert.equal(
      context.bgaPerformAction.calls[0][1].wound_allocations,
      JSON.stringify({ 8: 2, 9: 1 })
    );
  });

  it("submits Aenor only when bite wounds are confirmed", async () => {
    const context = {
      biteWoundsRemaining: 0,
      biteWoundsToApply: { 8: 1 },
      aenorPendingCharacterId: 8,
      bgaPerformAction: spy(async () => undefined),
      submitPendingAenorAbility: game.submitPendingAenorAbility,
      removeAenorCancelButton: spy(),
    };

    await game.confirmBiteWounds.call(context);

    assert.deepEqual(
      context.bgaPerformAction.calls.map((call) => call[0]),
      ["actUseAenorAbility", "actApplyBiteWounds"]
    );
    assert.equal(context.bgaPerformAction.calls[0][1].card_id, 8);
    assert.equal(context.aenorPendingCharacterId, 0);
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

  it("only enables burial for an eligible character from hand", () => {
    const button = { style: {} };
    documentElementOverrides.confirm_bury_character = button;
    const context = {
      buryCharacterEligibleCardIds: [8],
      hand: { unselectCard: spy() },
    };

    try {
      game.onBuryCharacterSelectionChange.call(
        context,
        [{ id: 9, is_character: "0" }],
        { id: 9, is_character: "0" }
      );
      assert.equal(context.hand.unselectCard.calls.length, 1);
      assert.equal(button.style.visibility, "hidden");

      game.onBuryCharacterSelectionChange.call(
        context,
        [{ id: 8, is_character: "1" }],
        { id: 8, is_character: "1" }
      );
      assert.equal(button.style.visibility, "visible");
    } finally {
      delete documentElementOverrides.confirm_bury_character;
    }
  });

  it("submits the selected hand character for burial", () => {
    const context = {
      buryCharacterEligibleCardIds: [8],
      hand: { getSelection: spy(() => [{ id: 8, is_character: "1" }]) },
      bgaPerformAction: spy(),
    };

    game.confirmBuryCharacter.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actBuryCharacter");
    assert.equal(context.bgaPerformAction.calls[0][1].card_id, 8);
  });

  it("selects a deck before confirming top-card burial", () => {
    const button = { style: {} };
    documentElementOverrides.confirm_bury_top_card = button;
    const context = {
      buryTopCardAvailableDecks: ["deck_rural", "deck_urban"],
      buryTopCardSelectedDeck: null,
    };

    try {
      game.selectBuryTopCardDeck.call(context, "deck_urban");

      assert.equal(context.buryTopCardSelectedDeck, "deck_urban");
      assert.equal(button.style.visibility, "visible");
    } finally {
      delete documentElementOverrides.confirm_bury_top_card;
    }
  });

  it("submits the selected deck for top-card burial", () => {
    const context = {
      buryTopCardAvailableDecks: ["deck_rural", "deck_urban"],
      buryTopCardSelectedDeck: "deck_rural",
      bgaPerformAction: spy(),
    };

    game.confirmBuryTopCard.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actBuryTopCard");
    assert.equal(context.bgaPerformAction.calls[0][1].location, "deck_rural");
  });

  it("selects a deck before confirming deck avoidance", () => {
    const button = { style: {} };
    documentElementOverrides.confirm_avoid_deck = button;
    const context = {
      avoidDeckAvailableDecks: ["deck_rural", "deck_urban"],
      avoidDeckSelectedDeck: null,
    };

    try {
      game.selectAvoidDeck.call(context, "deck_urban");

      assert.equal(context.avoidDeckSelectedDeck, "deck_urban");
      assert.equal(button.style.visibility, "visible");
    } finally {
      delete documentElementOverrides.confirm_avoid_deck;
    }
  });

  it("submits the selected deck for avoidance", () => {
    const context = {
      avoidDeckAvailableDecks: ["deck_rural", "deck_urban"],
      avoidDeckSelectedDeck: "deck_rural",
      bgaPerformAction: spy(),
    };

    game.confirmAvoidDeck.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actAvoidDeck");
    assert.equal(context.bgaPerformAction.calls[0][1].location, "deck_rural");
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

  it("does nothing when no managed disaster draw is active", () => {
    const context = {
      isTestMode: true,
      gamePhase: 2,
      bgaPerformAction: spy(),
    };

    game.onDisasterBagClick.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 0);
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

  it("draws both draft disasters with one click on the bag", () => {
    const context = {
      gamePhase: 1,
      draftDisasterPhase: "draw",
      bgaPerformAction: spy(),
    };

    game.onDisasterBagClick.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actDrawDraftDisasters");
  });

  it("selects and confirms a drafted disaster", () => {
    const firstToken = { classList: { add: spy(), remove: spy() } };
    const secondToken = { classList: { add: spy(), remove: spy() } };
    const button = { style: {} };
    documentElementOverrides["token-1"] = firstToken;
    documentElementOverrides["token-2"] = secondToken;
    documentElementOverrides.confirm_draft_disaster = button;
    const context = {
      draftDisasterEligibleIds: [1, 2],
      draftDisasterSelectedId: null,
      bgaPerformAction: spy(),
    };

    try {
      game.onDraftDisasterClick.call(context, { id: 2 });
      assert.equal(context.draftDisasterSelectedId, 2);
      assert.equal(secondToken.classList.add.calls.length, 1);
      assert.equal(button.style.visibility, "visible");

      game.confirmDraftDisaster.call(context);
      assert.equal(context.bgaPerformAction.calls.length, 1);
      assert.equal(context.bgaPerformAction.calls[0][0], "actResolveDraftDisaster");
      assert.equal(context.bgaPerformAction.calls[0][1].disaster_id, 2);
    } finally {
      delete documentElementOverrides["token-1"];
      delete documentElementOverrides["token-2"];
      delete documentElementOverrides.confirm_draft_disaster;
    }
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

  it("lets Boris consume an available resource to reveal both decks", () => {
    const context = {
      difficulty: 2,
      gamePhase: 1,
      bgaPerformAction: spy(),
    };

    game.onRessourceClick.call(context, {
      id: "ressource_break",
      consumed: 0,
    });

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actUseBorisResource");
    assert.equal(
      context.bgaPerformAction.calls[0][1].token_id,
      "ressource_break"
    );
  });

  it("does not let Boris consume an already consumed resource", () => {
    const context = {
      difficulty: 2,
      gamePhase: 1,
      isTestMode: false,
      bgaPerformAction: spy(),
    };

    game.onRessourceClick.call(context, {
      id: "ressource_stress",
      consumed: 1,
    });

    assert.equal(context.bgaPerformAction.calls.length, 0);
  });

  it("highlights only Boris resources that are still available", () => {
    const resourceElement = { classList: { toggle: spy() } };
    const context = { difficulty: 2, gamePhase: 1 };

    game.updateBorisResourceClickability.call(
      context,
      { id: "ressource_hunger", consumed: 0 },
      resourceElement
    );
    game.updateBorisResourceClickability.call(
      context,
      { id: "ressource_hunger", consumed: 1 },
      resourceElement
    );

    assert.deepEqual(resourceElement.classList.toggle.calls, [
      ["twd-boris-resource-available", true],
      ["twd-boris-resource-available", false],
    ]);
  });

  it("disables Boris resources during phase two", () => {
    const resourceElement = { classList: { toggle: spy() } };
    const context = {
      difficulty: 2,
      gamePhase: 2,
      isTestMode: false,
      bgaPerformAction: spy(),
    };
    const token = { id: "ressource_hunger", consumed: 0 };

    game.updateBorisResourceClickability.call(
      context,
      token,
      resourceElement
    );
    game.onRessourceClick.call(context, token);

    assert.deepEqual(resourceElement.classList.toggle.calls, [
      ["twd-boris-resource-available", false],
    ]);
    assert.equal(context.bgaPerformAction.calls.length, 0);
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
      getCardName: game.getCardName,
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

  it("does not flip a resource outside test mode", () => {
    const context = {
      isTestMode: false,
      bgaPerformAction: spy(),
    };

    game.onRessourceClick.call(context, { id: "ressource_hunger" });

    assert.equal(context.bgaPerformAction.calls.length, 0);
  });

  it("submits Aenor only when a disaster wound is confirmed", async () => {
    const context = {
      aenorSelectingCharacter: false,
      aenorPendingCharacterId: 8,
      bgaPerformAction: spy(async () => undefined),
      submitPendingAenorAbility: game.submitPendingAenorAbility,
      removeAenorCancelButton: spy(),
    };

    await game.confirmDisasterCharacteristic.call(context);

    assert.deepEqual(
      context.bgaPerformAction.calls.map((call) => call[0]),
      ["actUseAenorAbility", "actConfirmDisasterCharacteristic"]
    );
    assert.equal(context.bgaPerformAction.calls[0][1].card_id, 8);
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

  it("fast-memorises from the selected deck", () => {
    const context = { bgaPerformAction: spy() };

    game.onFastMemoriseRuralDeckClick.call(context);
    game.onFastMemoriseUrbanDeckClick.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actFastMemorise");
    assert.equal(context.bgaPerformAction.calls[0][1].location, "deck_rural");
    assert.equal(context.bgaPerformAction.calls[1][0], "actFastMemorise");
    assert.equal(context.bgaPerformAction.calls[1][1].location, "deck_urban");
  });

  it("limits quick memorisation from hand to two selected cards", () => {
    const thirdCard = { id: 3 };
    const context = {
      fastMemoriseMaximumCards: 2,
      hand: { unselectCard: spy() },
    };

    game.onFastMemoriseHandSelectionChange.call(
      context,
      [{ id: 1 }, { id: 2 }, thirdCard],
      thirdCard
    );

    assert.equal(context.hand.unselectCard.calls.length, 1);
    assert.equal(context.hand.unselectCard.calls[0][0], thirdCard);
  });

  it("confirms one or two cards selected from hand for quick memorisation", () => {
    const context = {
      hand: { getSelection: () => [{ id: "8" }, { id: 11 }] },
      bgaPerformAction: spy(),
    };

    game.confirmFastMemoriseFromHand.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actFastMemoriseFromHand");
    assert.equal(context.bgaPerformAction.calls[0][1].card_ids, "[8,11]");
  });

  it("confirms quick memorisation with no selected hand card", () => {
    const context = {
      hand: { getSelection: () => [] },
      bgaPerformAction: spy(),
    };

    game.confirmFastMemoriseFromHand.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actFastMemoriseFromHand");
    assert.equal(context.bgaPerformAction.calls[0][1].card_ids, "[]");
  });

  it("limits unremember to two selected cards", () => {
    const thirdCard = { id: 3 };
    const context = {
      unrememberMaximumCards: 2,
      brainstorm: { unselectCard: spy() },
    };

    game.onUnrememberSelectionChange.call(
      context,
      [{ id: 1 }, { id: 2 }, thirdCard],
      thirdCard
    );

    assert.equal(context.brainstorm.unselectCard.calls.length, 1);
    assert.equal(context.brainstorm.unselectCard.calls[0][0], thirdCard);
  });

  it("confirms the cards selected during unremember", () => {
    const context = {
      unrememberMaximumCards: 2,
      brainstorm: { getSelection: () => [{ id: "8" }, { id: 11 }] },
      bgaPerformAction: spy(),
    };

    game.confirmUnremember.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actConfirmUnremember");
    assert.equal(context.bgaPerformAction.calls[0][1].card_ids, "[8,11]");
  });

  it("limits avoid hand2 to two selected cards", () => {
    const thirdCard = { id: 3 };
    const context = {
      avoidHandMaximumCards: 2,
      hand: { unselectCard: spy() },
    };

    game.onAvoidHandSelectionChange.call(
      context,
      [{ id: 1 }, { id: 2 }, thirdCard],
      thirdCard
    );

    assert.equal(context.hand.unselectCard.calls.length, 1);
    assert.equal(context.hand.unselectCard.calls[0][0], thirdCard);
  });

  it("confirms one or two hand cards to escape without playing them", () => {
    const context = {
      hand: { getSelection: () => [{ id: "4" }, { id: 9 }] },
      bgaPerformAction: spy(),
    };

    game.confirmAvoidFromHand.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actAvoidFromHand");
    assert.equal(context.bgaPerformAction.calls[0][1].card_ids, "[4,9]");
  });

  it("confirms avoid hand2 without a selected card", () => {
    const context = {
      hand: { getSelection: () => [] },
      bgaPerformAction: spy(),
    };

    game.confirmAvoidFromHand.call(context);

    assert.equal(context.bgaPerformAction.calls[0][0], "actAvoidFromHand");
    assert.equal(context.bgaPerformAction.calls[0][1].card_ids, "[]");
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
  it("clears Aenor protection when it expires unused", () => {
    const setAenorCharacterHighlighted = spy();
    const context = {
      aenorProtectedCharacterId: 8,
      setAenorCharacterHighlighted,
    };

    game.notif_aenorProtectionExpired.call(context, {});

    assert.equal(setAenorCharacterHighlighted.calls[0][0], 8);
    assert.equal(setAenorCharacterHighlighted.calls[0][1], false);
    assert.equal(context.aenorProtectedCharacterId, 0);
  });

  it("moves empty disaster tokens from the reserve to the bag", () => {
    const removeAll = spy();
    const disasterBag = { id: "disasters_bag" };
    const context = {
      disastersReserve: { removeAll },
    };
    documentElementOverrides.disasters_bag = disasterBag;

    try {
      game.notif_emptyDisastersAdded.call(context, { count: 2 });

      assert.equal(removeAll.calls.length, 1);
      assert.equal(removeAll.calls[0][0].slideTo, disasterBag);
    } finally {
      delete documentElementOverrides.disasters_bag;
    }
  });

  it("removes destroyed disasters and returns the others to the bag", async () => {
    const removeCard = spy(async () => undefined);
    const disasterBag = { id: "disasters_bag" };
    const context = {
      disastersDrawnSlot: { removeCard },
    };
    const removed = { id: 1 };
    const returned = { id: 2 };
    documentElementOverrides.disasters_bag = disasterBag;

    try {
      await game.notif_disastersResolved.call(context, {
        removedDisasters: [removed],
        returnedDisasters: [returned],
      });

      assert.equal(removeCard.calls[0][0], removed);
      assert.equal(removeCard.calls[0].length, 1);
      assert.equal(removeCard.calls[1][0], returned);
      assert.equal(removeCard.calls[1][1].slideTo, disasterBag);
    } finally {
      delete documentElementOverrides.disasters_bag;
    }
  });

  it("shows a revealed card on top of its deck", async () => {
    const deck = {
      setCardNumber: spy(async () => undefined),
    };
    const context = {
      getLocation: spy(() => deck),
      setDeckState: game.setDeckState,
      deckTopTypes: {},
    };
    const card = { id: 20, type: "3", face_down: false };

    await game.notif_deckTopRevealed.call(context, {
      card,
      location: "deck_urban",
      cardNumber: 4,
    });

    assert.equal(context.getLocation.calls[0][0], "deck_urban");
    assert.equal(deck.setCardNumber.calls[0][0], 4);
    assert.equal(deck.setCardNumber.calls[0][1], card);
    assert.equal(context.deckTopTypes.deck_urban, "3");
  });

  it("returns brainstorm cards to their deck face up", async () => {
    const brainstorm = {};
    const ruralDeck = { addCard: spy(async () => undefined) };
    const context = {
      brainstorm,
      ruralDeck,
      deckTopTypes: { deck_rural: "4" },
      generateFakeCard: game.generateFakeCard,
      getLocation: (location) => location === "brainstorm" ? brainstorm : ruralDeck,
    };
    const card = { id: 20, type: "2", face_down: false };

    await game.moveCardToLocation.call(
      context,
      card,
      "deck_rural",
      "brainstorm"
    );

    assert.equal(ruralDeck.addCard.calls[0][0], card);
    assert.equal(ruralDeck.addCard.calls[0][1].fromStock, brainstorm);
    assert.equal(context.deckTopTypes.deck_rural, "2");
  });

  it("waits until the face-down Memory card is installed when Story Check starts", async () => {
    let finishAdding;
    const addPromise = new Promise((resolve) => {
      finishAdding = resolve;
    });
    const memory = { addCard: spy(() => addPromise) };
    const card = { id: 18, type: "2", face_down: true };
    const context = {
      memory,
      deckTopTypes: { memory: "2" },
      setGamePhaseDisplay: game.setGamePhaseDisplay,
      setHandVisible: spy(),
    };
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

  it("updates the protagonist and loss condition", async () => {
    const context = {
      protagonistSlot: { addCard: spy(async () => undefined) },
      hand: { removeAll: spy(async () => undefined) },
      updateAllBorisResourceClickability: spy(),
      lossCondition: 5,
    };
    const card = { id: 2, type: "1" };

    await game.notif_protagonistCardPlayed.call(context, {
      card,
      difficulty: 2,
      lossCondition: 3,
    });

    assert.equal(context.protagonistSlot.addCard.calls[0][0], card);
    assert.equal(context.protagonistSlot.addCard.calls[0][1].fromStock, context.hand);
    assert.equal(context.hand.removeAll.calls.length, 1);
    assert.equal(context.difficulty, 2);
    assert.equal(context.lossCondition, 3);
    assert.equal(context.updateAllBorisResourceClickability.calls.length, 1);
  });

  it("animates Boris merging, shuffling and redistributing the decks", async () => {
    const urbanTopCard = { id: "urban-top-card", type: "5" };
    const ruralTopCard = { id: "rural-top-card", type: "4" };
    const ruralDeck = {
      addCard: spy(async () => undefined),
      getTopCard: spy(() => ruralTopCard),
      setCardNumber: spy(async () => undefined),
      shuffle: spy(async () => undefined),
    };
    const urbanDeck = {
      addCard: spy(async () => undefined),
      getTopCard: spy(() => urbanTopCard),
      setCardNumber: spy(async () => undefined),
    };
    const context = {
      ruralDeck,
      urbanDeck,
      deckTopTypes: {},
      getLocation: (location) => location === "deck_rural" ? ruralDeck : urbanDeck,
      setDeckState: game.setDeckState,
    };
    const mergedTop = { id: "deck_rural-5-top-card", type: "5" };
    const shuffledTop = { id: "deck_rural-4-top-card", type: "4" };
    const redistributedRuralTop = { id: "deck_rural-5-top-card", type: "5" };
    const redistributedUrbanTop = { id: "deck_urban-4-top-card", type: "4" };

    await game.notif_borisDecksMerged.call(context, {
      ruralDeckNb: 36,
      urbanDeckNb: 0,
      ruralDeckTop: mergedTop,
      urbanDeckTop: null,
    });
    await game.notif_borisDeckShuffled.call(context, {
      ruralDeckNb: 36,
      ruralDeckTop: shuffledTop,
    });
    await game.notif_borisDecksRedistributed.call(context, {
      ruralDeckNb: 17,
      urbanDeckNb: 19,
      ruralDeckTop: redistributedRuralTop,
      urbanDeckTop: redistributedUrbanTop,
    });

    assert.equal(ruralDeck.addCard.calls[0][0], urbanTopCard);
    assert.equal(ruralDeck.addCard.calls[0][1].fromStock, urbanDeck);
    assert.equal(ruralDeck.shuffle.calls.length, 1);
    assert.equal(urbanDeck.addCard.calls[0][0], ruralTopCard);
    assert.equal(urbanDeck.addCard.calls[0][1].fromStock, ruralDeck);
    assert.equal(ruralDeck.setCardNumber.calls[2][0], 17);
    assert.equal(ruralDeck.setCardNumber.calls[2][1], redistributedRuralTop);
    assert.equal(urbanDeck.setCardNumber.calls[1][0], 19);
    assert.equal(urbanDeck.setCardNumber.calls[1][1], redistributedUrbanTop);
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

  it("shows a played card in the intermediate area during consequence resolution", async () => {
    const resolutionOrganiser = { style: { display: "none" } };
    documentElementOverrides.card_resolution_organiser = resolutionOrganiser;
    const hand = {};
    const currentCardResolution = { addCard: spy(async () => undefined) };
    const context = {
      hand,
      currentCardResolution,
      resolvingCardIds: new Set(),
    };
    const card = { id: 10, card_name: "Tallahassee" };

    try {
      await game.notif_cardResolutionStarted.call(context, { card });

      assert.equal(resolutionOrganiser.style.display, "block");
      assert.equal(context.resolvingCardIds.has(10), true);
      assert.equal(currentCardResolution.addCard.calls[0][0], card);
      assert.equal(currentCardResolution.addCard.calls[0][1].fromStock, hand);
    } finally {
      delete documentElementOverrides.card_resolution_organiser;
    }
  });

  it("moves a resolved card from the intermediate area and hides it in phase one", async () => {
    const resolutionOrganiser = { style: { display: "block" } };
    documentElementOverrides.card_resolution_organiser = resolutionOrganiser;
    const context = {
      gamePhase: 1,
      resolvingCardIds: new Set([10]),
      moveCardToLocation: spy(async () => undefined),
      setDeckState: spy(async () => undefined),
      clearSpecialDrawHighlight: spy(),
    };
    const card = { id: 10, card_name: "Tallahassee" };

    try {
      await game.notif_cardMoved.call(context, {
        card,
        source: "hand",
        destination: "memory",
      });

      assert.deepEqual(
        context.moveCardToLocation.calls[0],
        [card, "memory", "current_card_resolution", false]
      );
      assert.equal(context.resolvingCardIds.size, 0);
      assert.equal(resolutionOrganiser.style.display, "none");
    } finally {
      delete documentElementOverrides.card_resolution_organiser;
    }
  });

  it("updates a mixed deck back after its top card moves", async () => {
    const nextTopCard = {
      id: "deck_rural-5-top-card",
      type: "5",
      location: "deck_rural",
    };
    const context = {
      moveCardToLocation: spy(async () => undefined),
      setDeckState: spy(async () => undefined),
      clearSpecialDrawHighlight: spy(),
    };

    await game.notif_cardMoved.call(context, {
      card: { id: 10, type: "2" },
      source: "deck_rural",
      destination: "hand",
      sourceDeckNb: 17,
      sourceDeckTop: nextTopCard,
    });

    assert.deepEqual(context.setDeckState.calls[0], [
      "deck_rural",
      17,
      nextTopCard,
    ]);
  });

  it("removes the special draw highlight when the additional card is drawn", async () => {
    const highlightedCard = {
      classList: { add: spy(), remove: spy() },
    };
    documentElementOverrides["twd-card-13"] = highlightedCard;
    const context = {
      moveCardToLocation: spy(async () => undefined),
      setDeckState: spy(async () => undefined),
      clearSpecialDrawHighlight: game.clearSpecialDrawHighlight,
      specialDrawHighlightedCardId: 13,
    };

    try {
      await game.notif_cardMoved.call(context, {
        card: { id: 20, type: "2" },
        source: "deck_rural",
        destination: "hand",
        sourceDeckNb: 16,
      });

      assert.equal(
        highlightedCard.classList.remove.calls[0][0],
        "twd-highlight"
      );
      assert.equal(context.specialDrawHighlightedCardId, undefined);
    } finally {
      delete documentElementOverrides["twd-card-13"];
    }
  });

  it("keeps the current highlight when another special draw is triggered", async () => {
    const context = {
      moveCardToLocation: spy(async () => undefined),
      setDeckState: spy(async () => undefined),
      clearSpecialDrawHighlight: spy(),
    };

    await game.notif_cardMoved.call(context, {
      card: { id: 27, type: "3" },
      source: "deck_urban",
      destination: "memory",
      special: true,
      sourceDeckNb: 15,
    });

    assert.equal(context.clearSpecialDrawHighlight.calls.length, 0);
  });

  it("installs the new graveyard top after its top card is removed", async () => {
    const graveyard = {
      setCardNumber: spy(),
      addCard: spy(async () => undefined),
    };
    const context = {
      graveyard,
      moveCardToLocation: spy(async () => undefined),
      deckTopTypes: { graveyard: "2" },
      getLocation: () => graveyard,
      setDeckState: game.setDeckState,
    };
    const graveyardTop = {
      id: 41,
      type: "4",
      location: "graveyard",
    };

    await game.notif_cardMoved.call(context, {
      card: { id: 42, type: "3", location: "escaped" },
      source: "graveyard",
      destination: "escaped",
      graveyardNb: 1,
      graveyardTop,
    });

    assert.equal(graveyard.setCardNumber.calls[0][0], 1);
    assert.equal(graveyard.setCardNumber.calls[0][1], graveyardTop);
    assert.equal(context.deckTopTypes.graveyard, "4");
  });

  it("reveals and installs the current Story Check card", async () => {
    const memory = {
      setCardNumber: spy(),
      addCard: spy(async () => undefined),
      removeAll: spy(),
    };
    const currentCardResolution = {
      addCard: spy(async () => undefined),
    };
    const context = {
      memory,
      currentCardResolution,
      deckTopTypes: { memory: "2" },
      getLocation: () => memory,
      setDeckState: game.setDeckState,
    };
    const card = { id: 21 };
    const memoryTopCard = { id: 15, type: "2", face_down: true };

    await game.notif_storyCardRevealed.call(context, {
      card,
      memoryTopCard,
      memoryNb: 2,
    });

    assert.equal(currentCardResolution.addCard.calls[0][0], card);
    assert.equal(currentCardResolution.addCard.calls[0][1].fromStock, memory);
    assert.equal(memory.setCardNumber.calls[0][0], 2);
    assert.equal(memory.setCardNumber.calls[0][1], memoryTopCard);
  });

  it("moves a resolved character from the current Story card to the characters area", async () => {
    const currentCardResolution = {};
    const characters = { addCard: spy(async () => undefined) };
    const context = { currentCardResolution, characters };
    const card = { id: 8, is_character: "1" };

    await game.notif_characterPutInPlay.call(context, { card });

    assert.equal(characters.addCard.calls.length, 1);
    assert.equal(characters.addCard.calls[0][0], card);
    assert.equal(
      characters.addCard.calls[0][1].fromStock,
      currentCardResolution
    );
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
