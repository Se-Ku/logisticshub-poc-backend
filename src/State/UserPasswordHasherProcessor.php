<?php
namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UserInput;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserPasswordHasherProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof UserInput) {
            /** @var User|null $user */
            $user = null;

            // Retrieve the managed entity from Doctrine for PATCH/PUT updates
            if (isset($context['previous_data']) && $context['previous_data'] instanceof User) {
                $user = $this->entityManager->find(User::class, $context['previous_data']->getId());
            }

            // Fallback to creating a new user instance for POST operations
            if (!$user) {
                $user = new User();
            }

            if ($data->email !== null) {
                $user->setEmail($data->email);
            }

            if (!empty($data->roles)) {
                $user->setRoles($data->roles);
            }

            if (!empty($data->password)) {
                $hashedPassword = $this->passwordHasher->hashPassword($user, $data->password);
                $user->setPassword($hashedPassword);
            }

            return $this->persistProcessor->process($user, $operation, $uriVariables, $context);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
