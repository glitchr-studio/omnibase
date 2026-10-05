<?php

namespace Base\Entity\Thread;

use App\Entity\Thread;
use Base\Database\Attribute\Slugify;
use Base\Database\Attribute\Uploader;
use Doctrine\Common\Collections\ArrayCollection;
use Base\Database\Attribute\DiscriminatorEntry;

use Base\Database\Entity\Extension\TranslatableTrait;

use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Service\Model\IconizeInterface;
use Base\Service\Model\GraphInterface;
use Doctrine\Common\Collections\Collection;
use Base\Database\Attribute\Cache;

use Doctrine\ORM\Mapping as ORM;
use Base\Repository\Thread\TaxonRepository;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity(repositoryClass: TaxonRepository::class)]
#[ORM\InheritanceType( "JOINED" )]
#[Cache(usage: "NONSTRICT_READ_WRITE", associations:"ALL")]
#[ORM\DiscriminatorColumn(name: "class", type: "string")]
#[DiscriminatorEntry(value: "abstract")]
// A slug is unique among the taxa of one type: a menu's section and a blog's
// category may both be "desserts". (It was unique on the whole table, shared
// by every type, and the second one became "desserts-2".)
#[ORM\UniqueConstraint(name: "thread_taxon_type_slug", columns: ["class", "slug"])]
#[ORM\Index(name: "thread_taxon_slug", columns: ["slug"])]
class Taxon implements TranslatableInterface, IconizeInterface, GraphInterface
{
    use TranslatableTrait;

    public function __iconize(): ?array
    {
        return $this->getIcon() ? [$this->getIcon()] : null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ["fa-solid fa-sitemap"];
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getLabel() ?? $this->getSlug() ?? get_class($this);
    }

    public function __construct(?string $label = null, ?string $slug = null)
    {
        $this->setLabel($label);
        $this->slug = $slug;

        $this->threads = new ArrayCollection();
        $this->children = new ArrayCollection();
        $this->connexes = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type:"integer")]
    protected $id;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\Column(length:255)]
    #[Slugify(reference:"translations.label", perType: true)]
    protected $slug;

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    #[ORM\Column(type:"text", nullable:true)]
    #[Uploader(max_size:"2MB", mime_types:["image/*"])]
    protected $icon;

    /**
     * @return array|mixed|File|null
     * @throws \Exception
     */
    public function getIcon()
    {
        return Uploader::getPublic($this, "icon");
    }

    /**
     * @return array|mixed|File|null
     * @throws FilesystemException
     */
    public function getIconFile()
    {
        return Uploader::get($this, "icon");
    }

    /**
     * @return $this
     */
    public function setIcon($icon)
    {
        $this->icon = $icon;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Taxon::class, inversedBy:"children")]
    #[ORM\JoinColumn(onDelete: "SET NULL")]
    protected $parent;

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;
        if ($this->parent === null) {
            return $this;
        }

        $this->parent->addChild($this);
        return $this;
    }

    public function removeParent(self $parent): self
    {
        if ($this->parent->removeElement($parent)) {
            // set the owning side to null (unless already changed)
            if ($parent->getChildren() == $this) {
                $parent->setChildren(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Taxon::class, mappedBy: "parent", orphanRemoval: true, cascade:["persist"])]
    protected $children;
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(self $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children[] = $child;
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(self $child): self
    {
        if ($this->children->removeElement($child)) {
            // set the owning side to null (unless already changed)
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }

        return $this;
    }

    #[ORM\ManyToMany(targetEntity:Thread::class, mappedBy:"taxa")]
    protected $threads;

    public function getThreads(): Collection
    {
        return $this->threads;
    }

    public function addThread(Thread $thread): self
    {
        if (!$this->threads->contains($thread)) {
            $this->threads[] = $thread;
            $thread->addTag($this);
        }

        return $this;
    }

    public function removeThread(Thread $thread): self
    {
        if ($this->threads->removeElement($thread)) {
            $thread->removeTag($this);
        }

        return $this;
    }

    #[ORM\ManyToMany(targetEntity:Taxon::class)]
    protected $connexes;

    public function getConnexes(): Collection
    {
        return $this->connexes;
    }

    public function addConnex(self $connex): self
    {
        if (!$this->connexes->contains($connex)) {
            $this->connexes[] = $connex;
        }

        return $this;
    }

    public function removeConnex(self $connex): self
    {
        $this->connexes->removeElement($connex);
        return $this;
    }
}
