# Architecture

Browser / Admin / Embedded Website
        |
        v
AIKB PHP Web Application
        |
        +---- MySQL
        |
        v
DT-RAG API
        |
        +---- Qdrant
        |
        +---- AI / Embedding Provider

Only the AIKB public web endpoint normally needs internet exposure.
Keep MySQL, Qdrant and the internal RAG API private where possible.
