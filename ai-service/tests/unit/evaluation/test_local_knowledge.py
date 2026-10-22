from ai_service.domain.email import TenantId
from ai_service.domain.knowledge import TextChunk
from ai_service.infrastructure.knowledge.local import LocalEmbeddings, MemoryVectorStore


async def test_finds_the_fragment_that_shares_words_and_only_in_its_company() -> None:
    embeddings, store = LocalEmbeddings(), MemoryVectorStore()
    chunks = [
        TextChunk("Canarias, Ceuta y Melilla: 5 a 7 días laborables. Coste 14,95 €.", {}, 10),
        TextChunk("Tienes 30 días naturales para devolver cualquier producto.", {}, 10),
    ]
    batch = await embeddings.embed_documents([c.text for c in chunks])
    store.add(TenantId(1), 1, "Política de envíos", chunks, batch.vectors)

    query = await embeddings.embed_query("¿Hacéis envíos a Canarias? ¿Cuánto cuesta?")
    found = await store.search(
        TenantId(1), query.vector, embedding_model="local-bow", limit=5, min_similarity=0.15
    )
    other = await store.search(
        TenantId(2), query.vector, embedding_model="local-bow", limit=5, min_similarity=0.0
    )

    assert found[0].content.startswith("Canarias")
    assert found[0].similarity > 0.15
    assert other == []
    assert query.call.provider == "local"
