# LogisticsHub PoC
This application is the backend component of the LogisticsHub PoC.<br/>
To learn how this app fits into the whole system visit [logisticshub-poc](https://github.com/Se-Ku/logisticshub-poc)

# Usage scenarios
- public business logic API (core application)
- Message consumer worker (background task)
- CLI for administrators

# How to run
It is a standard Symfony application, exposing HTTP endpoints.<br/>
Install composer dependencies, generate JWT keys, set up a database, execute doctrine migration.<br/>

## Docker image
Since this is a PoC, the target audience is interested in details and easily available error messages.<br/>
Therefore, the provided Dockerfile intentionally builds the application in development mode.<br/>

Please note that the built image will require extra provisioning to run properly. <br/>
For a working example see how the docker image is provisioned in the showcase repository [logisticshub-poc](https://github.com/Se-Ku/logisticshub-poc)

# Implementation overview
More detailed information about the application itself.

## PHP + Symfony 7
The choice was made mainly due to the author's familiarity with the technology. <br/>
Other than that, the selected tools are right for the job and would be used in a real application. 

## API Platform (https://api-platform.com)
Symfony provides apt solutions for exposing (simple) API, 
but APIPlatform brings in some structure and nice to have features (i.e. auto generated docs).

With AP it is trivial to set up authenticated endpoints and provide CRUD interfaces for data entities. Which is perfect for a PoC and can be easily expanded later.<br/>

AP provides a standardised way to create custom endpoints, with middleware, DTOs, etc.

## Messenger
The backend exchanges messages through RabbitMQ. <br/>
Symfony provides standardised tools for processing the messages, configured at the framework level.

The built-in message consumer worker is especially useful. It is designed to be a long-running process, which is not typical use case of PHP.<br/>
It's trivial to set up a systemd service or use FrankenPHP's built-in support for Symfony workers (used for showcasing the system).

## Calling external service with circuit breaker and local fallback
Scenario: 
- frontend (the user) is calling the backend to calculate order's shipment cost
- backend contacts a microservice (which represents a 3rd party service) to get the calculation

This happens during one request, so the backend must be careful not to be blocked for too long (PHP processes are expensive to run).

Potential problems:
- the communication may take long time or timeout without a response (network issues)
- the response may contain an error

To solve these problems:
- Network timeout is relatively short.
- A circuit breaker algorithm is implemented. <br/>After a threshold of failed requests is reached, temporarily stop calling the external service and use local fallback mechanism  
- There's a local calculation fallback so users may still submit orders (which may be a bit unrealistic, but it's a PoC)

## JWT authentication
Although for this simple PoC a session cookie would be enough, 
using a token for authentication is a more viable approach for a multi-component application.<br/>
At some point the authentication module may be decoupled from the backend. It may become a new service, or a 3rd party auth solution could be used instead.<br/>




 
