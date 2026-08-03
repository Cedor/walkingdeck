import { readFileSync } from "node:fs";
import vm from "node:vm";
import assert from "node:assert/strict";
import { before, describe, it } from "node:test";

let game;

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
      getElementById: spy(() => ({ style: {}, classList: { add: spy() } })),
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

  it("submits the character selected as a bite target", () => {
    const context = {
      characters: { getSelection: () => [{ id: 8 }] },
      bgaPerformAction: spy(),
    };

    game.confirmBiteTarget.call(context);

    assert.equal(
      context.bgaPerformAction.calls[0][0],
      "actChooseBiteTarget"
    );
    assert.equal(context.bgaPerformAction.calls[0][1].card_id, 8);
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

  it("only draws a disaster during phase two", () => {
    const context = { gamePhase: 1, bgaPerformAction: spy() };

    game.onDisasterBagClick.call(context);
    context.gamePhase = 2;
    game.onDisasterBagClick.call(context);

    assert.equal(context.bgaPerformAction.calls.length, 1);
    assert.equal(context.bgaPerformAction.calls[0][0], "actDrawFromDisasterBag");
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
    let notificationFinished = false;

    const notification = game.notif_storyCheckStarted.call({ memory }, { memoryTopCard: card });
    notification.then(() => {
      notificationFinished = true;
    });
    await Promise.resolve();

    assert.equal(notificationFinished, false);
    assert.equal(memory.addCard.calls[0][0], card);

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
});
