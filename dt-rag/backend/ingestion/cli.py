"""
Manual CLI entrypoint for the rag-ingestion container. Foundation tool for
crawl testing, invoked explicitly:
    docker compose -p dt-rag run --rm rag-ingestion crawl --url https://example.com --max-pages 5

NOT a daemon, NOT scheduled, does NOT run automatically.

V2 correction (item 3): this CLI no longer indexes anything into Qdrant --
that capability was removed from the crawler/pipeline entirely, since
crawled pages must never become durable searchable knowledge without
corresponding canonical AIKB document/document_version data (see
ARCHITECTURE_PHASE_B.md and CHANGELOG.md). This command now ONLY crawls,
normalizes, and prints/reports results -- there is no non-dry-run indexing
mode anymore. `--kb-id` is retained as an argument for forward-compatibility
with a future Phase C handshake but is not currently used for anything.
"""
import argparse
import asyncio


async def _run_crawl(args):
    from app import vector_store
    from ingestion.crawler import crawl

    print(f"Starting manual crawl: {args.url} (max_pages={args.max_pages})")
    print("NOTE: this crawl does not index anything into Qdrant (see module docstring).")
    result = await crawl(
        origin_url=args.url,
        max_pages=args.max_pages,
        excluded_path_patterns=args.exclude or [],
    )
    print(f"Crawl finished: {len(result.pages)} pages fetched, {len(result.failed)} failed.")
    for p in result.pages:
        print(f"  - {p['url']} ({len(p['text'])} chars) title={p['title']!r}")
    if result.failed:
        print("Failed URLs:")
        for f in result.failed:
            print(f"  - {f['url']}: {f['reason']}")


def main():
    parser = argparse.ArgumentParser(description="dt-rag ingestion foundation CLI (manual, non-scheduled, crawl-only)")
    sub = parser.add_subparsers(dest="command", required=True)

    crawl_parser = sub.add_parser("crawl", help="Manually crawl a site and print results (does not index anything)")
    crawl_parser.add_argument("--kb-id", type=int, default=None, dest="kb_id", help="Reserved for future Phase C use; not used in Phase B.")
    crawl_parser.add_argument("--url", type=str, required=True)
    crawl_parser.add_argument("--max-pages", type=int, default=None, dest="max_pages")
    crawl_parser.add_argument("--exclude", action="append", help="Path pattern to exclude (repeatable)")

    args = parser.parse_args()

    if args.command == "crawl":
        asyncio.run(_run_crawl(args))
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
