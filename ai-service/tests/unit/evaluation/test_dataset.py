from pathlib import Path

import pytest
from pydantic import ValidationError

from ai_service.application.evaluation.dataset import Dataset, Ideal, Tenant, load_dataset
from ai_service.application.services.tool_catalog import SPECS
from ai_service.domain.category import Category

DATASET = Path(__file__).parents[3] / "evals" / "dataset.json"


def test_the_dataset_loads_and_ids_are_unique() -> None:
    dataset = load_dataset(DATASET)

    assert len(dataset.cases) >= 40


def test_every_intent_and_both_companies_are_covered() -> None:
    dataset = load_dataset(DATASET)
    intents = {i for c in dataset.cases for i in c.expect.intents}

    assert intents == set(Category)
    assert {c.tenant for c in dataset.cases} == set(Tenant)


def test_there_are_safety_cases_and_each_ideal() -> None:
    cases = load_dataset(DATASET).cases

    assert sum("seguridad" in c.tags for c in cases) >= 4
    assert sum(not c.expect.auto_send_ok for c in cases) >= 15
    assert {c.expect.ideal for c in cases} == set(Ideal)


def test_expected_tools_exist() -> None:
    for case in load_dataset(DATASET).cases:
        assert set(case.expect.tools) <= set(SPECS), case.id


def test_auto_send_as_ideal_requires_it_to_be_safe() -> None:
    raw = {
        "version": 1,
        "cases": [
            {
                "id": "caso-malo",
                "tenant": "shop",
                "from": "a@b.test",
                "subject": "x",
                "body": "y",
                "expect": {"auto_send_ok": False, "ideal": "auto_send"},
            }
        ],
    }

    with pytest.raises(ValidationError, match="auto_send_ok=false"):
        Dataset.model_validate(raw)


def test_select_filters_and_limits_in_order() -> None:
    dataset = load_dataset(DATASET)

    assert [c.id for c in dataset.select(limit=2)] == [c.id for c in dataset.cases[:2]]
    assert [c.id for c in dataset.select(only={"spam", "garantia"})] == ["garantia", "spam"]


def test_the_holdout_set_loads_and_does_not_repeat_cases() -> None:
    holdout = load_dataset(DATASET.with_name("holdout.json"))
    main_ids = {c.id for c in load_dataset(DATASET).cases}

    assert len(holdout.cases) >= 10
    assert not main_ids & {c.id for c in holdout.cases}
    assert any("seguridad" in c.tags for c in holdout.cases)
